<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\ActionInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\AtomicInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiMultiServerTestKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * `JsonApiRoute::of()` against the attributes the bundle's real routes give a request,
 * for every kind of route the loader emits.
 */
final class JsonApiRouteOfTest extends TestCase
{
    /** @var list<Kernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
    }

    #[Test]
    public function aCollectionRoute(): void
    {
        $route = $this->route(OpenApiMultiServerTestKernel::class, 'GET', '/public-items');

        self::assertEquals(new JsonApiRoute('default', 'public-items'), $route);
        self::assertEquals(new Target('public-items'), $route->target());
    }

    #[Test]
    public function aResourceRoute(): void
    {
        $route = $this->route(OpenApiMultiServerTestKernel::class, 'PATCH', '/public-items/7');

        self::assertEquals(new JsonApiRoute('default', 'public-items', '7'), $route);
        self::assertEquals(new Target('public-items', '7'), $route->target());
    }

    #[Test]
    public function aRelatedRoute(): void
    {
        $route = $this->route(ActionInMemoryTestKernel::class, 'GET', '/actionWidgets/7/owner');

        self::assertSame('owner', $route->relationship);
        self::assertFalse($route->isRelationshipEndpoint);
        self::assertEquals(new Target('actionWidgets', '7', 'owner'), $route->target());
    }

    #[Test]
    public function aRelationshipRoute(): void
    {
        $route = $this->route(ActionInMemoryTestKernel::class, 'DELETE', '/actionWidgets/7/relationships/owner');

        self::assertSame('owner', $route->relationship);
        self::assertTrue($route->isRelationshipEndpoint);
        self::assertEquals(new Target('actionWidgets', '7', 'owner', true), $route->target());
    }

    #[Test]
    public function aResourceScopedAction(): void
    {
        $route = $this->route(ActionInMemoryTestKernel::class, 'POST', '/actionWidgets/7/-actions/publish');

        self::assertSame('7', $route->id);
        self::assertSame('publish', $route->action);
        self::assertSame(ActionScope::Resource, $route->actionScope);
        self::assertFalse($route->isAtomic);
    }

    #[Test]
    public function aCollectionScopedAction(): void
    {
        $route = $this->route(ActionInMemoryTestKernel::class, 'PATCH', '/actionWidgets/-actions/recalculate');

        self::assertNull($route->id);
        self::assertSame('recalculate', $route->action);
        self::assertSame(ActionScope::Collection, $route->actionScope);
    }

    #[Test]
    public function theAtomicRoute(): void
    {
        $route = $this->route(AtomicInMemoryTestKernel::class, 'POST', '/operations');

        self::assertEquals(new JsonApiRoute('default', isAtomic: true), $route);
        self::assertNull($route->target());
    }

    #[Test]
    public function aNamedServersRoute(): void
    {
        $route = $this->route(OpenApiMultiServerTestKernel::class, 'GET', '/admin/admin-items/3');

        self::assertSame('admin', $route->server);
        self::assertSame('admin-items', $route->type);
    }

    #[Test]
    public function aRouteTheBundleDidNotEmitHasNone(): void
    {
        $request = $this->matched(OpenApiMultiServerTestKernel::class, 'GET', '/docs.json');

        self::assertFalse(JsonApiRoute::matches($request));
        self::assertNull(JsonApiRoute::of($request));
    }

    #[Test]
    public function anUnroutedRequestHasNone(): void
    {
        self::assertNull(JsonApiRoute::of(Request::create('/public-items')));
    }

    /**
     * @param class-string<Kernel> $kernelClass
     */
    private function route(string $kernelClass, string $method, string $path): JsonApiRoute
    {
        $route = JsonApiRoute::of($this->matched($kernelClass, $method, $path));
        self::assertNotNull($route);

        return $route;
    }

    /**
     * @param class-string<Kernel> $kernelClass
     */
    private function matched(string $kernelClass, string $method, string $path): Request
    {
        $kernel = new $kernelClass('test', false);
        $kernel->boot();
        $this->kernels[] = $kernel;

        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RequestMatcherInterface::class, $router);

        $request = Request::create($path, $method);
        $request->attributes->add($router->matchRequest($request));

        return $request;
    }
}
