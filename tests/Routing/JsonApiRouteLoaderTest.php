<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Routing;

use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the {@see JsonApiRouteLoader}'s Atomic Operations route emission —
 * specifically the fail-fast configuration guard: an atomic path
 * that equals a resource's collection path (`/{uriType}`) on the same server would
 * silently shadow that type's `POST` Create (both are `POST {path}`, and the atomic
 * route is emitted first), so the loader refuses it at route-loading time with a
 * clear, named {@see \LogicException} rather than shipping the shadow.
 */
#[Group('spec:atomic')]
final class JsonApiRouteLoaderTest extends TestCase
{
    #[Test]
    public function anAtomicPathEqualToAResourceCollectionPathIsRefused(): void
    {
        // The `operations` type's collection path is `/operations` — identical to the
        // default atomic path, so its Create would be shadowed.
        $loader = $this->loaderWithType('operations', atomicEnabled: true, atomicPath: '/operations');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The Atomic Operations path "/operations" collides with the collection path');

        $loader->load('.', JsonApiRouteLoader::ROUTE_TYPE);
    }

    #[Test]
    public function aDistinctAtomicPathEmitsBothRoutesCleanly(): void
    {
        // The `operations` type with a non-colliding atomic path: both the atomic route
        // and the type's Create are emitted, distinct.
        $loader = $this->loaderWithType('operations', atomicEnabled: true, atomicPath: '/-atomic');

        $routes = $loader->load('.', JsonApiRouteLoader::ROUTE_TYPE);

        $atomic = $routes->get('jsonapi.atomic_operations');
        self::assertNotNull($atomic);
        self::assertSame('/-atomic', $atomic->getPath());

        $create = $routes->get('jsonapi.operations.create');
        self::assertNotNull($create);
        self::assertSame('/operations', $create->getPath());
        self::assertSame(['POST'], $create->getMethods());
    }

    #[Test]
    public function aCollidingPathIsToleratedWhenTheExtensionIsDisabled(): void
    {
        // With the extension off no atomic route is emitted, so a same-named type is
        // not shadowed and the guard does not fire.
        $loader = $this->loaderWithType('operations', atomicEnabled: false, atomicPath: '/operations');

        $routes = $loader->load('.', JsonApiRouteLoader::ROUTE_TYPE);

        self::assertNull($routes->get('jsonapi.atomic_operations'));
        self::assertNotNull($routes->get('jsonapi.operations.create'));
    }

    #[Test]
    public function aSingletonIsServedAtItsPathWithNothingElse(): void
    {
        $loader = new JsonApiRouteLoader(
            routeDescriptorsByServer: ['default' => ['inboxes' => [
                'uriType' => 'inbox',
                'isResource' => true,
                'hasHydrator' => true,
                'hasRelations' => true,
                // A collection, create or delete is never emitted for a singleton.
                'operations' => \array_map(static fn(Operation $op): string => $op->value, Operation::cases()),
                'singleton' => true,
                'tags' => [],
                'responses' => [],
            ]]],
            actionRouteDescriptorsByServer: ['default' => [
                ['uriType' => 'inbox', 'type' => 'inboxes', 'path' => 'mark-read', 'methods' => ['POST'], 'scope' => 'Resource', 'name' => '', 'singleton' => true],
            ]],
            atomicEnabled: true,
            atomicPath: '/inbox',
        );

        $routes = [];
        foreach ($loader->load('.', JsonApiRouteLoader::ROUTE_TYPE) as $name => $route) {
            if ($route->getDefault('_jsonapi_type') !== 'inboxes') {
                continue;
            }
            self::assertTrue($route->getDefault('_jsonapi_singleton'), $name);
            $routes[$name] = \implode(',', $route->getMethods()) . ' ' . $route->getPath();
        }

        self::assertSame([
            'jsonapi.inboxes.action.resource.mark-read' => 'POST /inbox/-actions/mark-read',
            'jsonapi.inboxes.show' => 'GET /inbox',
            'jsonapi.inboxes.update' => 'PATCH /inbox',
            'jsonapi.inboxes.relationship.show' => 'GET /inbox/relationships/{relationship}',
            'jsonapi.inboxes.relationship.update' => 'PATCH /inbox/relationships/{relationship}',
            'jsonapi.inboxes.relationship.add' => 'POST /inbox/relationships/{relationship}',
            'jsonapi.inboxes.relationship.remove' => 'DELETE /inbox/relationships/{relationship}',
            'jsonapi.inboxes.related.show' => 'GET /inbox/{relationship}',
        ], $routes);
    }

    private function loaderWithType(string $uriType, bool $atomicEnabled, string $atomicPath): JsonApiRouteLoader
    {
        $descriptors = [
            'default' => [
                $uriType => [
                    'uriType' => $uriType,
                    'isResource' => true,
                    'hasHydrator' => true,
                    'hasRelations' => false,
                    'operations' => [Operation::FetchCollection->value, Operation::Create->value],
                    'tags' => [],
                    'responses' => [],
                ],
            ],
        ];

        return new JsonApiRouteLoader(
            routeDescriptorsByServer: $descriptors,
            atomicEnabled: $atomicEnabled,
            atomicPath: $atomicPath,
        );
    }
}
