<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The route-scoped `kernel.exception` listener: it owns every error on JSON:API
 * routes (it acts only when the matched route carries
 * {@see self::ROUTE_MARKER}), so even failures are spec-compliant JSON:API
 * documents.
 *
 * Mapping:
 *  - a core {@see JsonApiExceptionInterface} renders through its own
 *    `getErrors()` / `getStatusCode()` via {@see ErrorResponse::fromException()}.
 *    This arm is **always first** and is never intercepted or overridden by a
 *    mapper or the `json_api.exceptions` config map — a core JSON:API exception
 *    always renders natively (the seam's invariant, bundle ADR 0073);
 *  - otherwise the tagged {@see ExceptionMapperInterface} mappers are consulted in
 *    descending tag `priority` order (default `0`), first non-null result wins —
 *    the application's seam to map its own domain / third-party exceptions
 *    (including the bundle's config-driven {@see ConfiguredExceptionMapper} at the
 *    low `-1000` fallback priority, which maps a `json_api.exceptions` class to its
 *    configured HTTP status);
 *  - a Symfony {@see HttpExceptionInterface} (firewall `401`/`403`, routing
 *    `404`, …) maps to a status-keyed JSON:API error;
 *  - a Symfony Security {@see \Symfony\Component\Security\Core\Exception\AccessDeniedException}
 *    (thrown by the declarative-authorization layer, bundle ADR 0043) maps to `403`
 *    and an {@see \Symfony\Component\Security\Core\Exception\AuthenticationException}
 *    (an unauthenticated request the firewall surfaces) maps to `401` — neither is an
 *    `HttpExceptionInterface`, so both are mapped explicitly (guarded by
 *    `\class_exists` so the listener compiles without `symfony/security-core`);
 *  - anything else becomes a `500`, with `{exception, file, line, trace}` in the
 *    error object's `meta` gated on `kernel.debug` and the throwable logged.
 *
 * The throwable→500 mapping delegates to core's public, stateless
 * {@see InternalServerError::for()} seam, so this listener and core's
 * `ErrorHandlerMiddleware` produce a byte-identical generic-500 error object.
 */
final class ExceptionListener
{
    public const string ROUTE_MARKER = '_jsonapi';

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
        // The application-extensible exception → JSON:API-error seam (bundle ADR
        // 0073): consulted for any throwable that is NOT a core
        // JsonApiExceptionInterface (that arm stays first and is never overridden).
        private readonly iterable $mappers = [],
        // Optional Symfony Security collaborators, present only when
        // symfony/security-core is installed: they let an AccessDeniedException from
        // the declarative-authorization layer (bundle ADR 0043) map to 401 for an
        // unauthenticated request (where the firewall would otherwise prompt to
        // authenticate) and 403 for an authenticated-but-denied one.
        private readonly ?\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokenStorage = null,
        private readonly ?\Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface $trustResolver = null,
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->attributes->get(self::ROUTE_MARKER) !== true) {
            return;
        }

        $throwable = $event->getThrowable();

        $serverName = $request->attributes->get('_jsonapi_server');
        $server = $this->servers->get(\is_string($serverName) ? $serverName : null);

        $psrRequest = $request->attributes->get(RequestListener::PSR_REQUEST_ATTRIBUTE);
        if (!$psrRequest instanceof ServerRequestInterface) {
            $psrRequest = $this->psrHttpFactory->createRequest($request);
        }

        $errorResponse = $this->toErrorResponse($throwable);

        $psrResponse = $errorResponse->toPsrResponse($server, $psrRequest);

        $event->setResponse($this->httpFoundationFactory->createResponse($psrResponse));
    }

    private function toErrorResponse(\Throwable $throwable): ErrorResponse
    {
        // INVARIANT (bundle ADR 0073): a core JSON:API exception always renders
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
            return ErrorResponse::fromErrors($this->statusError($throwable->getStatusCode(), $throwable));
        }

        // The declarative-authorization layer (bundle ADR 0043) throws a Symfony
        // Security AccessDeniedException; an unauthenticated request the firewall
        // surfaces is an AuthenticationException. Neither is an HttpExceptionInterface,
        // so both are mapped here (guarded so the listener compiles without
        // symfony/security-core). An AccessDeniedException maps to 401 when the
        // request is unauthenticated (authentication would unlock it) and 403
        // otherwise, mirroring Symfony's own access-denied handling.
        if (\class_exists(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class)
            && $throwable instanceof \Symfony\Component\Security\Core\Exception\AccessDeniedException
        ) {
            return ErrorResponse::fromErrors($this->statusError($this->isAuthenticated() ? 403 : 401, $throwable));
        }

        if (\class_exists(\Symfony\Component\Security\Core\Exception\AuthenticationException::class)
            && $throwable instanceof \Symfony\Component\Security\Core\Exception\AuthenticationException
        ) {
            return ErrorResponse::fromErrors($this->statusError(401, $throwable));
        }

        $this->logger?->error($throwable->getMessage(), ['exception' => $throwable]);

        return ErrorResponse::fromErrors(InternalServerError::for($throwable, $this->debug));
    }

    private function statusError(int $status, \Throwable $throwable): Error
    {
        return new Error(
            status: (string) $status,
            title: $this->reasonPhrase($status),
            detail: $this->debug ? $throwable->getMessage() : '',
        );
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
