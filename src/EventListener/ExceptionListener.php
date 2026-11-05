<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\Exception\AuthenticationRequired;
use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\MethodNotAllowed;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The route-scoped `kernel.exception` listener: it owns every error on JSON:API
 * routes (it acts only when the matched route carries
 * {@see JsonApiRoute::MARKER}), so even failures are spec-compliant JSON:API
 * documents.
 *
 * Mapping:
 *  - a core {@see JsonApiExceptionInterface} renders through its own
 *    `getErrors()` / `getStatusCode()` via {@see ErrorResponse::fromException()}.
 *    This arm is **always first** and is never intercepted or overridden by a
 *    mapper or the `json_api.exceptions` config map — a core JSON:API exception
 *    always renders natively (the seam's invariant);
 *  - otherwise the tagged {@see ExceptionMapperInterface} mappers are consulted in
 *    descending tag `priority` order (default `0`), first non-null result wins —
 *    the application's seam to map its own domain / third-party exceptions
 *    (including the bundle's config-driven {@see ConfiguredExceptionMapper} at the
 *    low `-1000` fallback priority, which maps a `json_api.exceptions` class to its
 *    configured HTTP status);
 *  - a Symfony {@see HttpExceptionInterface} (routing `404`, …) maps to a
 *    status-keyed JSON:API error, except a `401`/`403`, which renders as core's
 *    {@see AuthenticationRequired}/{@see AccessDenied} like the Security exceptions.
 *    The exception's own headers (`WWW-Authenticate`, `Retry-After`, …) carry onto
 *    the response, apart from `Content-Type` and `Content-Length`;
 *  - a Symfony Security {@see \Symfony\Component\Security\Core\Exception\AccessDeniedException}
 *    (thrown by the declarative-authorization layer or `access_control`) renders as
 *    core's {@see AccessDenied} (`403`, `ACCESS_DENIED`), or {@see AuthenticationRequired}
 *    (`401`, `AUTHENTICATION_REQUIRED`) when the request is unauthenticated, and an
 *    {@see \Symfony\Component\Security\Core\Exception\AuthenticationException}
 *    as {@see AuthenticationRequired}. Neither is an `HttpExceptionInterface`, so both
 *    are mapped explicitly (guarded by `\class_exists` so the listener compiles without
 *    `symfony/security-core`). The detail is the exception message in debug only;
 *  - anything else becomes a `500`, with `{exception, file, line, trace}` in the
 *    error object's `meta` gated on `kernel.debug` and the throwable logged.
 *
 * **Authentication failures go to the firewall first.** When the request's firewall
 * has an entry point, {@see onKernelException()} (priority `128`) leaves an `AuthenticationException`
 * and an unauthenticated access denial on the event and records it in
 * {@see DEFERRED_AUTHENTICATION_ATTRIBUTE}, so Symfony Security's own listener
 * (priority `1`) runs the firewall's entry point. A `401` the entry point returns
 * gets a JSON:API {@see AuthenticationRequired} body in {@see onKernelResponse()},
 * keeping every header the entry point set apart from `Content-Type` and
 * `Content-Length`; any other status it returns, such as a login redirect, passes
 * through. If the entry point declines by throwing, Security leaves a `401`
 * `HttpException`, which {@see onKernelExceptionAfterFirewall()} (priority `-64`)
 * renders like any other error. Without an entry point, and for an authenticated
 * access denial (`403`), there is nothing to defer to, so both render at priority
 * `128`.
 *
 * The throwable→500 mapping delegates to core's public, stateless
 * {@see InternalServerError::for()} seam, so this listener and core's
 * `ErrorHandlerMiddleware` produce a byte-identical generic-500 error object.
 *
 * **Atomic Operations.** On the Atomic Operations route (the one carrying the
 * {@see JsonApiRouteLoader::ATOMIC_ATTRIBUTE} marker) an error document declares the
 * atomic extension on its `Content-Type` `ext` parameter only when the request named
 * it, on the `Content-Type` `ext` or on any `Accept` range's `ext`. The headers are
 * read directly, since the error may come before negotiation ran: a `405`, a firewall
 * `401`/`403`. A request that never named the extension gets a plain JSON:API error. A
 * `415` ({@see MediaTypeUnsupported}) or `406` ({@see MediaTypeUnacceptable}) never
 * declares it, since negotiation failed.
 *
 * **A method the path does not accept.** The router throws a `405` before any route
 * matches, so the request carries no marker. The listener asks the router which route
 * each allowed method reaches on that path; when one of them is a JSON:API route, the
 * `405` renders as core's {@see MethodNotAllowed} under that route's server, with its
 * `Allow` header, and on the atomic path under the rule above. A path none of whose
 * routes is a JSON:API endpoint keeps Symfony's own handling.
 */
final class ExceptionListener
{
    /**
     * @deprecated since 1.3, use {@see JsonApiRoute::MARKER}, or {@see JsonApiRoute::matches()}
     *             to test a request
     */
    public const string ROUTE_MARKER = JsonApiRoute::MARKER;

    /**
     * The request attribute holding the authentication failure this listener left to
     * the firewall's entry point.
     */
    public const string DEFERRED_AUTHENTICATION_ATTRIBUTE = '_jsonapi_deferred_authentication';

    /**
     * @param iterable<ExceptionMapperInterface> $mappers the tagged exception
     *                                                     mappers, in descending
     *                                                     tag-priority order; the
     *                                                     config-driven
     *                                                     {@see ConfiguredExceptionMapper}
     *                                                     sits at the low `-1000`
     *                                                     fallback priority
     */
    public function __construct(
        private readonly ServerProvider $servers,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly HttpFoundationFactory $httpFoundationFactory,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
        // The application-extensible exception → JSON:API-error seam: consulted for any
        // throwable that is NOT a core
        // JsonApiExceptionInterface (that arm stays first and is never overridden).
        private readonly iterable $mappers = [],
        // Optional Symfony Security collaborators, present only when
        // symfony/security-core is installed: they let an AccessDeniedException from
        // the declarative-authorization layer map to 401 for an
        // unauthenticated request (where the firewall would otherwise prompt to
        // authenticate) and 403 for an authenticated-but-denied one.
        private readonly ?\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokenStorage = null,
        private readonly ?\Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface $trustResolver = null,
        // Re-matches the path of a request the router refused with a 405, to tell
        // whether the path belongs to a JSON:API server.
        private readonly ?RouterInterface $router = null,
        // Tells whether the request's firewall has an entry point, so an
        // authentication failure can go to it first.
        private readonly ?\Symfony\Component\Security\Http\FirewallMapInterface $firewallMap = null,
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $throwable = $event->getThrowable();

        if (JsonApiRoute::matches($request) && $this->defersToFirewall($request, $throwable)) {
            $request->attributes->set(self::DEFERRED_AUTHENTICATION_ATTRIBUTE, $throwable);

            return;
        }

        if (!JsonApiRoute::matches($request)) {
            $routed = $this->jsonApiRouteForUnroutedMethod($request, $throwable);
            if ($routed === null) {
                return;
            }

            // Render against a copy carrying the JSON:API route's defaults, so the server
            // and its mount resolve as they would for a matched request.
            $throwable = new MethodNotAllowed($request->getMethod(), $this->allowedMethods($throwable), $throwable);
            $request = $routed;
        }

        $event->setResponse($this->render($request, $throwable));
    }

    /**
     * Renders what the firewall left on the event for a JSON:API route, such as the
     * `401` Security throws when the entry point declines, or any error a listener
     * between this one and {@see onKernelException()} did not answer.
     */
    public function onKernelExceptionAfterFirewall(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!JsonApiRoute::matches($request)) {
            return;
        }

        $request->attributes->remove(self::DEFERRED_AUTHENTICATION_ATTRIBUTE);
        $event->setResponse($this->render($request, $event->getThrowable()));
    }

    /**
     * Gives the firewall entry point's `401` a JSON:API body, keeping its headers. Any
     * other status the entry point chose, such as a login redirect, passes through.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $deferred = $request->attributes->get(self::DEFERRED_AUTHENTICATION_ATTRIBUTE);
        $response = $event->getResponse();
        if (!$deferred instanceof \Throwable || $response->getStatusCode() !== 401 || !JsonApiRoute::matches($request)) {
            return;
        }

        $request->attributes->remove(self::DEFERRED_AUTHENTICATION_ATTRIBUTE);
        $rendered = $this->render($request, new AuthenticationRequired($this->debugDetail($deferred), $deferred));
        $content = $rendered->getContent();
        if ($content === false) {
            return;
        }

        $response->setContent($content);
        $response->headers->set('Content-Type', $rendered->headers->get('Content-Type'));
        $response->headers->remove('Content-Length');
    }

    /**
     * Renders `$throwable` as a JSON:API error document under the request's server,
     * declaring the atomic extension where the request named it.
     */
    private function render(Request $request, \Throwable $throwable): Response
    {
        $server = $this->servers->forRequest($request);

        $psrRequest = $request->attributes->get(RequestListener::PSR_REQUEST_ATTRIBUTE);
        if (!$psrRequest instanceof ServerRequestInterface) {
            $psrRequest = $this->psrHttpFactory->createRequest($request);
        }

        $errorResponse = $this->toErrorResponse($throwable);

        if ($request->attributes->get(JsonApiRouteLoader::ATOMIC_ATTRIBUTE) === true
            && !$throwable instanceof MediaTypeUnsupported
            && !$throwable instanceof MediaTypeUnacceptable
            && self::namesAtomicExtension($psrRequest)
        ) {
            $errorResponse = $errorResponse->withExtensions([AtomicExtension::URI]);
        }

        $response = $this->httpFoundationFactory->createResponse($errorResponse->toPsrResponse($server, $psrRequest));

        if ($throwable instanceof HttpExceptionInterface && !$throwable instanceof JsonApiExceptionInterface) {
            foreach ($throwable->getHeaders() as $name => $value) {
                if (\in_array(\strtolower((string) $name), ['content-type', 'content-length'], true)) {
                    continue;
                }
                $response->headers->set((string) $name, $value);
            }
        }

        return $response;
    }

    /**
     * Whether Symfony's firewall should answer this authentication failure first, so
     * its entry point can add the challenge headers: an {@see \Symfony\Component\Security\Core\Exception\AuthenticationException},
     * or an access denial for an unauthenticated request, behind a firewall that has
     * an entry point.
     */
    private function defersToFirewall(Request $request, \Throwable $throwable): bool
    {
        if (!$this->firewallMap instanceof \Symfony\Bundle\SecurityBundle\Security\FirewallMap
            || $this->firewallMap->getFirewallConfig($request)?->getEntryPoint() === null
        ) {
            return false;
        }

        if (\class_exists(\Symfony\Component\Security\Core\Exception\AuthenticationException::class)
            && $throwable instanceof \Symfony\Component\Security\Core\Exception\AuthenticationException
        ) {
            return true;
        }

        return \class_exists(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class)
            && $throwable instanceof \Symfony\Component\Security\Core\Exception\AccessDeniedException
            && !$this->isAuthenticated();
    }

    /**
     * Whether the request named the atomic extension on its `Content-Type` or `Accept`
     * `ext` parameter, read straight off the headers as negotiation may not have run.
     */
    private static function namesAtomicExtension(ServerRequestInterface $request): bool
    {
        $request = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        return \in_array(AtomicExtension::URI, $request->getAppliedExtensions(), true)
            || \in_array(AtomicExtension::URI, $request->getRequestedExtensions(), true);
    }

    /**
     * When the router refused the request's method, a copy of the request carrying the
     * defaults of the JSON:API route one of the allowed methods reaches on the same path,
     * or `null` when none of them is a JSON:API route.
     */
    private function jsonApiRouteForUnroutedMethod(Request $request, \Throwable $throwable): ?Request
    {
        if ($this->router === null || !$throwable instanceof MethodNotAllowedHttpException) {
            return null;
        }

        $context = $this->router->getContext();
        $method = $context->getMethod();

        try {
            foreach ($this->allowedMethods($throwable) as $allowed) {
                // Newer matchers read the method off the request they are given, older
                // ones off the shared context, so both carry the allowed method.
                $probe = $request->duplicate();
                $probe->setMethod($allowed);
                $context->setMethod($allowed);

                try {
                    $defaults = $this->router instanceof RequestMatcherInterface
                        ? $this->router->matchRequest($probe)
                        : $this->router->match($probe->getPathInfo());
                } catch (RoutingException) {
                    continue;
                }

                if (($defaults[JsonApiRoute::MARKER] ?? null) === true) {
                    return $request->duplicate(attributes: [...$request->attributes->all(), ...$defaults]);
                }
            }
        } finally {
            $context->setMethod($method);
        }

        return null;
    }

    /**
     * The methods the router said the path accepts.
     *
     * @return list<string>
     */
    private function allowedMethods(\Throwable $throwable): array
    {
        $previous = $throwable->getPrevious();
        if ($previous instanceof MethodNotAllowedException) {
            return \array_values($previous->getAllowedMethods());
        }

        $allow = $throwable instanceof HttpExceptionInterface ? ($throwable->getHeaders()['Allow'] ?? '') : '';
        if (!\is_string($allow) || \trim($allow) === '') {
            return [];
        }

        return \array_values(\array_filter(\array_map(\trim(...), \explode(',', $allow)), static fn(string $method): bool => $method !== ''));
    }

    private function toErrorResponse(\Throwable $throwable): ErrorResponse
    {
        // INVARIANT: a core JSON:API exception always renders
        // natively. This arm is first and is never intercepted or overridden by a
        // mapper or the json_api.exceptions config map.
        if ($throwable instanceof JsonApiExceptionInterface) {
            return ErrorResponse::fromException($throwable);
        }

        // The application-extensible seam: consulted only for a throwable that is
        // not a core JsonApiExceptionInterface (the arm above). The mappers are
        // priority-ordered; the bundle's config-driven ConfiguredExceptionMapper is
        // the low-priority fallback, so an app mapper is consulted first.
        foreach ($this->mappers as $mapper) {
            $response = $mapper->map($throwable);
            if ($response !== null) {
                return $response;
            }
        }

        if ($throwable instanceof HttpExceptionInterface) {
            // A firewall's 401/403 carries the same stable code as the Security
            // exceptions below, so a client can dispatch on it whatever raised it.
            return match ($throwable->getStatusCode()) {
                401 => ErrorResponse::fromException(new AuthenticationRequired($this->debugDetail($throwable), $throwable)),
                403 => ErrorResponse::fromException(new AccessDenied($this->debugDetail($throwable), $throwable)),
                default => ErrorResponse::fromErrors($this->statusError($throwable->getStatusCode(), $throwable)),
            };
        }

        // The declarative-authorization layer throws a Symfony
        // Security AccessDeniedException; an unauthenticated request the firewall
        // surfaces is an AuthenticationException. Neither is an HttpExceptionInterface,
        // so both are mapped here (guarded so the listener compiles without
        // symfony/security-core). An AccessDeniedException maps to 401 when the
        // request is unauthenticated (authentication would unlock it) and 403
        // otherwise, mirroring Symfony's own access-denied handling.
        if (\class_exists(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class)
            && $throwable instanceof \Symfony\Component\Security\Core\Exception\AccessDeniedException
        ) {
            return ErrorResponse::fromException($this->isAuthenticated()
                ? new AccessDenied($this->debugDetail($throwable), $throwable)
                : new AuthenticationRequired($this->debugDetail($throwable), $throwable));
        }

        if (\class_exists(\Symfony\Component\Security\Core\Exception\AuthenticationException::class)
            && $throwable instanceof \Symfony\Component\Security\Core\Exception\AuthenticationException
        ) {
            return ErrorResponse::fromException(new AuthenticationRequired($this->debugDetail($throwable), $throwable));
        }

        $this->logger?->error($throwable->getMessage(), ['exception' => $throwable]);

        return ErrorResponse::fromErrors(InternalServerError::for($throwable, $this->debug));
    }

    private function statusError(int $status, \Throwable $throwable): Error
    {
        return new Error(
            status: (string) $status,
            title: $this->reasonPhrase($status),
            detail: $this->debugDetail($throwable),
        );
    }

    /**
     * The throwable's message as the error detail in debug, else empty (omitted).
     */
    private function debugDetail(\Throwable $throwable): string
    {
        return $this->debug ? $throwable->getMessage() : '';
    }

    /**
     * Whether the request carries a real (non-anonymous) authenticated token, used
     * to decide 401 vs 403 for an access-denial. Falls back to `false` (→ 401) when
     * the Security collaborators are absent — but this path is only reached for a
     * Security exception, which cannot arise without them.
     */
    private function isAuthenticated(): bool
    {
        if ($this->tokenStorage === null || $this->trustResolver === null) {
            return false;
        }

        $token = $this->tokenStorage->getToken();

        return $token !== null && $this->trustResolver->isAuthenticated($token);
    }

    private function reasonPhrase(int $status): string
    {
        return HttpReasonPhrase::of($status);
    }
}
