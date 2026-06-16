<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\EventListener;

use haddowg\JsonApiBundle\EventListener\ExceptionListener;
use haddowg\JsonApiBundle\EventListener\ResponseHeadersListener;
use haddowg\JsonApiBundle\Http\ResponseHeadersRegistry;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Characterizes the route-scoping and the no-clobber guard of the
 * {@see ResponseHeadersListener} (bundle ADR 0054) directly — the parts the
 * functional suite cannot reach: a non-JSON:API route is untouched, and an
 * explicit app-set `Cache-Control` is left alone.
 */
final class ResponseHeadersListenerTest extends TestCase
{
    #[Test]
    public function aNonJsonApiRouteIsUntouched(): void
    {
        // No _jsonapi marker on the request → the listener returns immediately.
        $request = new Request();
        $response = $this->dispatch($request, new Response('', 200), 'widgets');

        self::assertNull($response->getMaxAge());
    }

    #[Test]
    public function anExplicitCacheControlIsNotClobbered(): void
    {
        $request = $this->jsonApiGet('widgets');

        $response = new Response('', 200);
        $response->setMaxAge(5); // the app configured caching itself

        $this->dispatch($request, $response, 'widgets', cache: ['max_age' => 999]);

        self::assertSame(5, $response->getMaxAge());
    }

    #[Test]
    public function aCacheableGetWithoutExplicitCachingGetsTheDeclaredHeaders(): void
    {
        $request = $this->jsonApiGet('widgets');
        $response = new Response('', 200);

        $this->dispatch($request, $response, 'widgets', cache: ['max_age' => 60]);

        self::assertSame(60, $response->getMaxAge());
    }

    private function jsonApiGet(string $type): Request
    {
        $request = Request::create('/widgets', 'GET');
        $request->attributes->set(ExceptionListener::ROUTE_MARKER, true);
        $request->attributes->set(TargetResolver::TYPE_ATTRIBUTE, $type);

        return $request;
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function dispatch(Request $request, Response $response, string $type, array $cache = []): Response
    {
        $registry = new ResponseHeadersRegistry(
            byType: $cache === [] ? [] : [$type => ['cache' => $cache]],
        );

        $listener = new ResponseHeadersListener($registry, new TargetResolver());

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $listener->onKernelResponse($event);

        return $event->getResponse();
    }
}
