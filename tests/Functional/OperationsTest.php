<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OperationsTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The operation-exposure witness (ADR 0025): a type declares which of the five
 * CRUD operations it exposes via the operations allow-list, and the route loader
 * emits exactly one route per declared operation. A read-only resource
 * (`ledgers`), a create-only resource (`signals`) and a routed standalone
 * serializer (`beacons`) all fall out of the same mechanism. Operation gating is
 * a routing/registration concern, identical on every provider, so it is witnessed
 * on the in-memory kernel only. Unexposed verbs are asserted unrouted against the
 * route collection (not via an HTTP request, which the framework would log as an
 * uncaught 404).
 */
final class OperationsTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OperationsTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aReadOnlyResourceEmitsOnlyItsGetRoutes(): void
    {
        $routes = $this->routePathsByMethod();

        self::assertContains('GET /ledgers', $routes);
        self::assertContains('GET /ledgers/{id}', $routes);

        self::assertNotContains('POST /ledgers', $routes);
        self::assertNotContains('PATCH /ledgers/{id}', $routes);
        self::assertNotContains('DELETE /ledgers/{id}', $routes);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aCreateOnlyResourceEmitsOnlyItsPostRoute(): void
    {
        $routes = $this->routePathsByMethod();

        self::assertContains('POST /signals', $routes);

        self::assertNotContains('GET /signals', $routes);
        self::assertNotContains('GET /signals/{id}', $routes);
        self::assertNotContains('PATCH /signals/{id}', $routes);
        self::assertNotContains('DELETE /signals/{id}', $routes);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aRoutedStandaloneSerializerEmitsOnlyItsDeclaredReadRoute(): void
    {
        $routes = $this->routePathsByMethod();

        self::assertContains('GET /beacons/{id}', $routes);

        self::assertNotContains('GET /beacons', $routes);
        self::assertNotContains('POST /beacons', $routes);
        self::assertNotContains('PATCH /beacons/{id}', $routes);
        self::assertNotContains('DELETE /beacons/{id}', $routes);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aStandaloneSerializerEmitsNoRelationshipRoutes(): void
    {
        $paths = [];
        foreach ($this->routeCollectionPaths() as $path) {
            $paths[] = $path;
        }

        self::assertNotContains('/beacons/{id}/relationships/{relationship}', $paths);
        self::assertNotContains('/beacons/{id}/{relationship}', $paths);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function exposedReadOperationsRespond(): void
    {
        self::assertSame(200, $this->handle('/ledgers')->getStatusCode());
        self::assertSame(200, $this->handle('/ledgers/l1')->getStatusCode());
        self::assertSame(200, $this->handle('/beacons/b1')->getStatusCode());
    }

    #[Test]
    #[Group('spec:crud')]
    public function theExposedCreateOperationPersists(): void
    {
        $response = $this->handle('/signals', 'POST', [
            'data' => [
                'type' => 'signals',
                'attributes' => ['name' => 'launch'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('signals', $data['type'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('launch', $attributes['name'] ?? null);
    }

    /**
     * The booted router's route collection paths as `"<METHOD> <path>"` strings,
     * one per (path, method) pair, for asserting which operations are routed.
     *
     * @return list<string>
     */
    private function routePathsByMethod(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection() as $route) {
            $methods = $route->getMethods();
            if ($methods === []) {
                $methods = ['ANY'];
            }
            foreach ($methods as $method) {
                $routes[] = $method . ' ' . $route->getPath();
            }
        }

        return $routes;
    }

    /**
     * The booted router's route collection paths (ignoring method).
     *
     * @return list<string>
     */
    private function routeCollectionPaths(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $paths = [];
        foreach ($router->getRouteCollection() as $route) {
            $paths[] = $route->getPath();
        }

        return $paths;
    }
}
