<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\EventListener;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Characterizes the route-scoped `kernel.exception` listener: an unexpected
 * `\Throwable` on a marked route renders a generic `500` JSON:API document, and
 * the rendered error object is byte-identical to core's
 * {@see InternalServerError::for()} seam (the listener delegates the 500 mapping
 * to core rather than re-implementing it).
 */
final class ExceptionListenerTest extends TestCase
{
    #[Test]
    #[Group('spec:errors')]
    public function unexpectedThrowableIsRedactedWhenDebugIsOff(): void
    {
        $throwable = new \RuntimeException('leaky secret detail', 42);

        $error = $this->handle($throwable, debug: false);

        self::assertSame('500', $error['status'] ?? null);
        self::assertSame('Internal Server Error', $error['title'] ?? null);
        self::assertArrayNotHasKey('code', $error);
        self::assertArrayNotHasKey('detail', $error);
        self::assertArrayNotHasKey('meta', $error);

        self::assertSame($this->seamErrorArray($throwable, false), $error);
    }

    #[Test]
    #[Group('spec:errors')]
    public function unexpectedThrowableIsVerboseWhenDebugIsOn(): void
    {
        $throwable = new \RuntimeException('leaky secret detail', 42);

        $error = $this->handle($throwable, debug: true);

        self::assertSame('500', $error['status'] ?? null);
        self::assertSame('42', $error['code'] ?? null);
        self::assertSame('Internal Server Error', $error['title'] ?? null);
        self::assertSame('leaky secret detail', $error['detail'] ?? null);

        $meta = $error['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(\RuntimeException::class, $meta['exception'] ?? null);
        self::assertArrayHasKey('file', $meta);
        self::assertArrayHasKey('line', $meta);
        self::assertIsArray($meta['trace'] ?? null);

        self::assertSame($this->seamErrorArray($throwable, true), $error);
    }

    #[Test]
    #[Group('spec:errors')]
    public function accessDeniedMapsTo403WhenAuthenticated(): void
    {
        $response = $this->fire(
            new \Symfony\Component\Security\Core\Exception\AccessDeniedException('nope'),
            authenticated: true,
        );

        self::assertSame(403, $response->getStatusCode());
        $error = $this->firstError($response);
        self::assertSame('403', $error['status'] ?? null);
        self::assertSame('ACCESS_DENIED', $error['code'] ?? null);
        self::assertSame('Forbidden', $error['title'] ?? null);
        self::assertArrayNotHasKey('detail', $error);
    }

    #[Test]
    #[Group('spec:errors')]
    public function accessDeniedMapsTo401WhenUnauthenticated(): void
    {
        $response = $this->fire(
            new \Symfony\Component\Security\Core\Exception\AccessDeniedException('nope'),
            authenticated: false,
        );

        self::assertSame(401, $response->getStatusCode());
        $error = $this->firstError($response);
        self::assertSame('401', $error['status'] ?? null);
        self::assertSame('AUTHENTICATION_REQUIRED', $error['code'] ?? null);
        self::assertSame('Unauthorized', $error['title'] ?? null);
        self::assertArrayNotHasKey('detail', $error);
    }

    #[Test]
    #[Group('spec:errors')]
    public function authenticationExceptionMapsTo401(): void
    {
        $response = $this->fire(
            new \Symfony\Component\Security\Core\Exception\AuthenticationException('login required'),
            authenticated: true,
        );

        self::assertSame(401, $response->getStatusCode());
        $error = $this->firstError($response);
        self::assertSame('401', $error['status'] ?? null);
        self::assertSame('AUTHENTICATION_REQUIRED', $error['code'] ?? null);
        self::assertSame('Unauthorized', $error['title'] ?? null);
        self::assertArrayNotHasKey('detail', $error);
    }

    #[Test]
    #[Group('spec:errors')]
    public function anAuthFailureCarriesTheExceptionMessageAsDetailInDebugOnly(): void
    {
        $denied = $this->firstError($this->fire(
            new \Symfony\Component\Security\Core\Exception\AccessDeniedException('nope'),
            authenticated: true,
            debug: true,
        ));
        self::assertSame('ACCESS_DENIED', $denied['code'] ?? null);
        self::assertSame('nope', $denied['detail'] ?? null);

        $unauthenticated = $this->firstError($this->fire(
            new \Symfony\Component\Security\Core\Exception\AuthenticationException('login required'),
            authenticated: false,
            debug: true,
        ));
        self::assertSame('AUTHENTICATION_REQUIRED', $unauthenticated['code'] ?? null);
        self::assertSame('login required', $unauthenticated['detail'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function anHttp401Or403CarriesTheSameCodeAsTheSecurityExceptions(): void
    {
        $unauthorized = $this->fire(
            new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer', 'token expired'),
            authenticated: false,
        );
        self::assertSame(401, $unauthorized->getStatusCode());
        $error = $this->firstError($unauthorized);
        self::assertSame('AUTHENTICATION_REQUIRED', $error['code'] ?? null);
        self::assertSame('Unauthorized', $error['title'] ?? null);
        self::assertArrayNotHasKey('detail', $error);

        $forbidden = $this->fire(
            new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('firewall says no'),
            authenticated: true,
            debug: true,
        );
        self::assertSame(403, $forbidden->getStatusCode());
        $error = $this->firstError($forbidden);
        self::assertSame('ACCESS_DENIED', $error['code'] ?? null);
        self::assertSame('Forbidden', $error['title'] ?? null);
        self::assertSame('firewall says no', $error['detail'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function anotherHttpStatusStaysCodeless(): void
    {
        $response = $this->fire(
            new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('gone'),
            authenticated: true,
        );

        self::assertSame(404, $response->getStatusCode());
        $error = $this->firstError($response);
        self::assertSame('Not Found', $error['title'] ?? null);
        self::assertArrayNotHasKey('code', $error);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function atomicRouteHeaders(): iterable
    {
        $plain = 'application/vnd.api+json';
        $atomic = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

        yield 'no ext named' => [['HTTP_ACCEPT' => $plain, 'CONTENT_TYPE' => $plain], $plain];
        yield 'no headers' => [[], $plain];
        yield 'ext on Content-Type' => [['CONTENT_TYPE' => $atomic], $atomic];
        yield 'ext on Accept' => [['HTTP_ACCEPT' => $atomic], $atomic];
        yield 'ext on a later Accept range' => [['HTTP_ACCEPT' => 'text/html, ' . $atomic . ';q=0.9'], $atomic];
        yield 'another ext only' => [['HTTP_ACCEPT' => 'application/vnd.api+json; ext="https://example.com/ext/other"'], $plain];
    }

    /**
     * @param array<string, string> $server
     */
    #[Test]
    #[Group('spec:atomic')]
    #[DataProvider('atomicRouteHeaders')]
    public function anErrorOnTheAtomicRouteDeclaresTheExtOnlyWhenTheRequestNamedIt(array $server, string $contentType): void
    {
        $badRequest = $this->fireOnAtomicRoute(new QueryParamUnrecognized('bogus'), $server);
        self::assertSame(400, $badRequest->getStatusCode());
        self::assertSame($contentType, $badRequest->headers->get('Content-Type'));

        $unauthorized = $this->fireOnAtomicRoute(new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer'), $server);
        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertSame($contentType, $unauthorized->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aNegotiationFailureOnTheAtomicRouteNeverDeclaresTheExt(): void
    {
        $atomic = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $server = ['HTTP_ACCEPT' => $atomic, 'CONTENT_TYPE' => $atomic];

        $unsupported = $this->fireOnAtomicRoute(new MediaTypeUnsupported($atomic), $server);
        self::assertSame(415, $unsupported->getStatusCode());
        self::assertSame('application/vnd.api+json', $unsupported->headers->get('Content-Type'));

        $unacceptable = $this->fireOnAtomicRoute(new MediaTypeUnacceptable($atomic), $server);
        self::assertSame(406, $unacceptable->getStatusCode());
        self::assertSame('application/vnd.api+json', $unacceptable->headers->get('Content-Type'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function anHttpExceptionsHeadersCarryOntoTheRenderedResponse(): void
    {
        $unavailable = $this->fire(
            new \Symfony\Component\HttpKernel\Exception\HttpException(503, 'down', headers: ['Retry-After' => '120', 'Content-Type' => 'text/html']),
            authenticated: true,
        );
        self::assertSame(503, $unavailable->getStatusCode());
        self::assertSame('120', $unavailable->headers->get('Retry-After'));
        self::assertSame('application/vnd.api+json', $unavailable->headers->get('Content-Type'));

        $notAllowed = $this->fire(
            new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(['GET', 'PATCH']),
            authenticated: true,
        );
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertSame(['GET, PATCH'], $notAllowed->headers->all('allow'));

        $unauthorized = $this->fire(
            new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer realm="api"'),
            authenticated: false,
        );
        self::assertSame('Bearer realm="api"', $unauthorized->headers->get('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function authenticationFailures(): iterable
    {
        yield 'unauthenticated access denial' => [new \Symfony\Component\Security\Core\Exception\AccessDeniedException('denied')];
        yield 'authentication exception' => [new \Symfony\Component\Security\Core\Exception\AuthenticationException('login required')];
    }

    #[Test]
    #[Group('spec:errors')]
    #[DataProvider('authenticationFailures')]
    public function anAuthenticationFailureIsLeftToAFirewallWithAnEntryPoint(\Throwable $throwable): void
    {
        $event = $this->exceptionEvent($throwable);
        $this->firewalledListener(authenticated: false, entryPoint: 'app.entry_point')->onKernelException($event);

        self::assertNull($event->getResponse());
        self::assertSame($throwable, $event->getThrowable());
        self::assertSame($throwable, $event->getRequest()->attributes->get(ExceptionListener::DEFERRED_AUTHENTICATION_ATTRIBUTE));
    }

    #[Test]
    #[Group('spec:errors')]
    #[DataProvider('authenticationFailures')]
    public function anAuthenticationFailureRendersAtOnceWhenTheFirewallHasNoEntryPoint(\Throwable $throwable): void
    {
        $event = $this->exceptionEvent($throwable);
        $this->firewalledListener(authenticated: false, entryPoint: null)->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($event->getRequest()->attributes->has(ExceptionListener::DEFERRED_AUTHENTICATION_ATTRIBUTE));
    }

    #[Test]
    #[Group('spec:errors')]
    public function anAuthenticatedAccessDenialRendersAt403WithoutDeferring(): void
    {
        $event = $this->exceptionEvent(new \Symfony\Component\Security\Core\Exception\AccessDeniedException('denied'));
        $this->firewalledListener(authenticated: true, entryPoint: 'app.entry_point')->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', $this->firstError($response)['code'] ?? null);
    }

    #[Test]
    #[Group('spec:errors')]
    public function theLateListenerRendersWhatTheFirewallLeftOnAJsonApiRoute(): void
    {
        $listener = $this->firewalledListener(authenticated: false, entryPoint: 'app.entry_point');
        $event = $this->exceptionEvent(new \Symfony\Component\Security\Core\Exception\AuthenticationException('login required'));
        $listener->onKernelException($event);

        // What Security leaves when the entry point declines.
        $event->setThrowable(new \Symfony\Component\HttpKernel\Exception\HttpException(401, 'login required'));
        $listener->onKernelExceptionAfterFirewall($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTHENTICATION_REQUIRED', $this->firstError($response)['code'] ?? null);
        self::assertFalse($event->getRequest()->attributes->has(ExceptionListener::DEFERRED_AUTHENTICATION_ATTRIBUTE));

        $plain = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/plain'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('boom'));
        $listener->onKernelExceptionAfterFirewall($plain);
        self::assertNull($plain->getResponse());
    }

    #[Test]
    #[Group('spec:errors')]
    public function theEntryPoints401GetsAJsonApiBodyAndKeepsItsHeaders(): void
    {
        $request = $this->deferredRequest(new \Symfony\Component\Security\Core\Exception\AuthenticationException('login required'));
        $entryPoint = new Response('Sign in', 401, [
            'WWW-Authenticate' => 'Bearer realm="api"',
            'Content-Type' => 'text/plain',
            'Content-Length' => '7',
        ]);
        $entryPoint->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('seen', '1'));

        $response = $this->respond($request, $entryPoint, debug: true);

        self::assertSame($entryPoint, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->has('Content-Length'));
        self::assertSame('Bearer realm="api"', $response->headers->get('WWW-Authenticate'));
        self::assertCount(1, $response->headers->getCookies());
        $error = $this->firstError($response);
        self::assertSame('AUTHENTICATION_REQUIRED', $error['code'] ?? null);
        self::assertSame('login required', $error['detail'] ?? null);
        self::assertFalse($request->attributes->has(ExceptionListener::DEFERRED_AUTHENTICATION_ATTRIBUTE));
    }

    #[Test]
    #[Group('spec:errors')]
    public function anEntryPointRedirectAndAnUndeferredResponsePassThrough(): void
    {
        $redirect = new \Symfony\Component\HttpFoundation\RedirectResponse('/login');
        $response = $this->respond($this->deferredRequest(new \Symfony\Component\Security\Core\Exception\AuthenticationException('login required')), $redirect);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
        self::assertStringNotContainsString('errors', (string) $response->getContent());

        $request = Request::create('/articles');
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);
        $untouched = $this->respond($request, new Response('nope', 401));
        self::assertSame('nope', $untouched->getContent());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function theEntryPoints401OnTheAtomicRouteDeclaresTheExtWhenTheRequestNamedIt(): void
    {
        $atomic = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = $this->deferredRequest(
            new \Symfony\Component\Security\Core\Exception\AuthenticationException('login required'),
            Request::create('/operations', 'POST', server: ['HTTP_ACCEPT' => $atomic, 'CONTENT_TYPE' => $atomic]),
        );
        $request->attributes->set(JsonApiRouteLoader::ATOMIC_ATTRIBUTE, true);

        $response = $this->respond($request, new Response('', 401));

        self::assertSame($atomic, $response->headers->get('Content-Type'));
    }

    /**
     * Fires the listener for `$throwable` on the atomic route of a request carrying `$server`.
     *
     * @param array<string, string> $server
     */
    private function fireOnAtomicRoute(\Throwable $throwable, array $server): Response
    {
        $listener = new ExceptionListener($this->serverProvider(), $this->psrHttpFactory(), new HttpFoundationFactory());

        $request = Request::create('/operations', 'POST', server: $server);
        if (!isset($server['HTTP_ACCEPT'])) {
            // Request::create() fills in a browser Accept.
            $request->headers->remove('Accept');
        }
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);
        $request->attributes->set(JsonApiRouteLoader::ATOMIC_ATTRIBUTE, true);

        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * Fires the listener for `$throwable` on a marked route and returns the first
     * rendered error object.
     *
     * @return array<string, mixed>
     */
    private function handle(\Throwable $throwable, bool $debug): array
    {
        $listener = new ExceptionListener(
            $this->serverProvider(),
            $this->psrHttpFactory(),
            new HttpFoundationFactory(),
            debug: $debug,
        );

        $request = Request::create('/articles', 'GET');
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);

        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(500, $response->getStatusCode());

        return $this->firstError($response);
    }

    /**
     * Fires the listener for a Security exception with a token storage + trust
     * resolver reflecting `$authenticated`, returning the rendered response so the
     * mapped status (401 vs 403) can be asserted.
     */
    private function fire(\Throwable $throwable, bool $authenticated, bool $debug = false): Response
    {
        $tokenStorage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $trustResolver = $this->createStub(\Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface::class);
        if ($authenticated) {
            $token = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
            $tokenStorage->setToken($token);
            $trustResolver->method('isAuthenticated')->willReturn(true);
        } else {
            $trustResolver->method('isAuthenticated')->willReturn(false);
        }

        $listener = new ExceptionListener(
            $this->serverProvider(),
            $this->psrHttpFactory(),
            new HttpFoundationFactory(),
            debug: $debug,
            logger: null,
            tokenStorage: $tokenStorage,
            trustResolver: $trustResolver,
        );

        $request = Request::create('/articles', 'GET');
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);

        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * A listener behind a firewall whose entry point is `$entryPoint`, with a token
     * reflecting `$authenticated`.
     */
    private function firewalledListener(bool $authenticated, ?string $entryPoint, bool $debug = false): ExceptionListener
    {
        $tokenStorage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $trustResolver = $this->createStub(\Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface::class);
        $trustResolver->method('isAuthenticated')->willReturn($authenticated);
        if ($authenticated) {
            $tokenStorage->setToken($this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class));
        }

        $context = new \Symfony\Bundle\SecurityBundle\Security\FirewallContext(
            [],
            null,
            null,
            new \Symfony\Bundle\SecurityBundle\Security\FirewallConfig('main', 'security.user_checker', entryPoint: $entryPoint),
        );
        $contexts = new class ($context) implements ContainerInterface {
            public function __construct(private readonly \Symfony\Bundle\SecurityBundle\Security\FirewallContext $context) {}

            public function get(string $id): mixed
            {
                return $this->context;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        return new ExceptionListener(
            $this->serverProvider(),
            $this->psrHttpFactory(),
            new HttpFoundationFactory(),
            debug: $debug,
            tokenStorage: $tokenStorage,
            trustResolver: $trustResolver,
            firewallMap: new \Symfony\Bundle\SecurityBundle\Security\FirewallMap($contexts, ['security.firewall.map.context.main' => null]),
        );
    }

    private function exceptionEvent(\Throwable $throwable): ExceptionEvent
    {
        $request = Request::create('/articles', 'GET');
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    /**
     * A JSON:API route request the listener left to the firewall for `$throwable`.
     */
    private function deferredRequest(\Throwable $throwable, ?Request $request = null): Request
    {
        $request ??= Request::create('/articles', 'GET');
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);
        $request->attributes->set(ExceptionListener::DEFERRED_AUTHENTICATION_ATTRIBUTE, $throwable);

        return $request;
    }

    private function respond(Request $request, Response $response, bool $debug = false): Response
    {
        $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->firewalledListener(authenticated: false, entryPoint: 'app.entry_point', debug: $debug)->onKernelResponse($event);

        return $event->getResponse();
    }

    /**
     * The error object core's seam produces for the same throwable+debug flag,
     * via the exact `ErrorResponse` rendering path the listener uses, decoded to
     * the document's first error object.
     *
     * @return array<string, mixed>
     */
    private function seamErrorArray(\Throwable $throwable, bool $debug): array
    {
        $psrResponse = ErrorResponse::fromErrors(InternalServerError::for($throwable, $debug))
            ->toPsrResponse($this->server(), $this->psrHttpFactory()->createRequest(Request::create('/articles', 'GET')));

        $decoded = \json_decode((string) $psrResponse->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['errors'] ?? null);
        self::assertIsArray($decoded['errors'][0] ?? null);

        /** @var array<string, mixed> $error */
        $error = $decoded['errors'][0];

        return $error;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(Response $response): array
    {
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['errors'] ?? null);
        self::assertIsArray($decoded['errors'][0] ?? null);

        /** @var array<string, mixed> $error */
        $error = $decoded['errors'][0];

        return $error;
    }

    private function serverProvider(): ServerProvider
    {
        $factory = $this->serverFactory();

        // A minimal name → factory locator over the single `default` server.
        $factories = new class ($factory) implements ContainerInterface {
            public function __construct(private readonly ServerFactory $default) {}

            public function get(string $id): mixed
            {
                if ($id === ServerProvider::DEFAULT_SERVER) {
                    return $this->default;
                }

                throw new \LogicException(\sprintf('No server factory "%s" registered.', $id));
            }

            public function has(string $id): bool
            {
                return $id === ServerProvider::DEFAULT_SERVER;
            }
        };

        return new ServerProvider($factories);
    }

    private function server(): Server
    {
        return $this->serverFactory()->create();
    }

    /**
     * A real {@see ServerFactory} over an empty resource set — enough to render
     * an error document (no resources are resolved on the error path).
     */
    private function serverFactory(): ServerFactory
    {
        $psr17 = new Psr17Factory();

        $emptyServices = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException(\sprintf('No service "%s" registered.', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $handler = new class implements \haddowg\JsonApi\Operation\OperationHandlerInterface {
            public function handle(
                JsonApiOperationInterface $operation,
            ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse {
                throw new \LogicException('The error path never dispatches an operation.');
            }
        };

        return new ServerFactory(
            new ResourceLocator($emptyServices, []),
            $psr17,
            $psr17,
            'https://example.test',
            '1.1',
            $handler,
        );
    }

    private function psrHttpFactory(): PsrHttpFactory
    {
        $factory = new Psr17Factory();

        return new PsrHttpFactory($factory, $factory, $factory, $factory);
    }
}
