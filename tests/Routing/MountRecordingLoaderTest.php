<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Routing;

use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\MountRecordingLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The {@see MountRecordingLoader} records each JSON:API route's import prefix as
 * its mount once the whole collection is loaded.
 */
#[Group('spec:document-links')]
final class MountRecordingLoaderTest extends TestCase
{
    #[Test]
    public function theLoaderRecordsTheEmittedPathOnEveryRoute(): void
    {
        $routes = $this->jsonApiRoutes();

        $show = $routes->get('jsonapi.articles.show');
        self::assertNotNull($show);
        self::assertSame('/articles/{id}', $show->getDefault(JsonApiRouteLoader::PATH_ATTRIBUTE));
        self::assertNull($show->getDefault(JsonApiRouteLoader::MOUNT_ATTRIBUTE));
    }

    #[Test]
    public function aPrefixedImportRecordsItsPrefixAsTheMount(): void
    {
        $routes = $this->load(function (): RouteCollection {
            $routes = $this->jsonApiRoutes();
            $routes->addPrefix('/api/v5');

            return $routes;
        });

        foreach (['jsonapi.articles.index', 'jsonapi.articles.show', 'jsonapi.atomic_operations'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name);
            self::assertSame('/api/v5', $route->getDefault(JsonApiRouteLoader::MOUNT_ATTRIBUTE), $name);
        }
    }

    #[Test]
    public function aPlaceholderPrefixIsRecordedAsItsPattern(): void
    {
        $routes = $this->load(function (): RouteCollection {
            $routes = $this->jsonApiRoutes();
            $routes->addPrefix('/{tenant}/api');

            return $routes;
        });

        self::assertSame('/{tenant}/api', $routes->get('jsonapi.articles.show')?->getDefault(JsonApiRouteLoader::MOUNT_ATTRIBUTE));
    }

    #[Test]
    public function anUnprefixedImportOrAForeignRouteRecordsNoMount(): void
    {
        $routes = $this->load(function (): RouteCollection {
            $routes = $this->jsonApiRoutes();
            $routes->add('home', new Route('/api/v5/home'));

            return $routes;
        });

        self::assertNull($routes->get('jsonapi.articles.show')?->getDefault(JsonApiRouteLoader::MOUNT_ATTRIBUTE));
        self::assertNull($routes->get('home')?->getDefault(JsonApiRouteLoader::MOUNT_ATTRIBUTE));
    }

    private function load(\Closure $routes): RouteCollection
    {
        $loaded = (new MountRecordingLoader(new ClosureLoader()))->load($routes);
        self::assertInstanceOf(RouteCollection::class, $loaded);

        return $loaded;
    }

    private function jsonApiRoutes(): RouteCollection
    {
        $loader = new JsonApiRouteLoader(
            routeDescriptorsByServer: [
                'default' => [
                    'articles' => [
                        'uriType' => 'articles',
                        'isResource' => true,
                        'hasHydrator' => true,
                        'hasRelations' => true,
                        'operations' => [Operation::FetchCollection->value, Operation::FetchOne->value],
                        'tags' => [],
                        'responses' => [],
                    ],
                ],
            ],
            atomicEnabled: true,
        );

        return $loader->load('default', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
