<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApiBundle\OpenApi\Metadata\MountServers;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\MountRecordingLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Maps the mounts recorded on a server's routes to OpenAPI `servers` (bundle ADR 0121).
 */
#[Group('spec:openapi')]
final class MountServersTest extends TestCase
{
    #[Test]
    public function anUnmountedServerAdvertisesNoServers(): void
    {
        self::assertSame([], $this->servers($this->import('default')));
    }

    #[Test]
    public function aMountedServerAdvertisesItsMountAsARelativeUrl(): void
    {
        self::assertSame([['url' => '/api/v5']], $this->servers($this->import('default', '/api/v5')));
    }

    #[Test]
    public function onlyTheRequestedServersRoutesCount(): void
    {
        $routes = $this->import('default', '/api/v5');
        $routes->addCollection($this->import('admin', '/admin', 'admin'));
        $routes->add('home', new Route('/elsewhere/home', ['_jsonapi_server' => 'default']));

        self::assertSame([['url' => '/api/v5']], $this->servers($routes));
        self::assertSame([['url' => '/admin']], $this->servers($routes, 'admin'));
    }

    #[Test]
    public function eachDistinctMountIsOneServerInSortedOrder(): void
    {
        $routes = $this->import('default', '/v6', 'v6');
        $routes->addCollection($this->import('default', '/v5', 'v5'));
        $routes->addCollection($this->import('default', '/v6', 'v6b'));

        self::assertSame([['url' => '/v5'], ['url' => '/v6']], $this->servers($routes));
    }

    #[Test]
    public function anUnprefixedImportBesideAMountedOneIsTheRoot(): void
    {
        $routes = $this->import('default', '/api/v5', 'v5');
        $routes->addCollection($this->import('default', null, 'root'));

        self::assertSame([['url' => '/'], ['url' => '/api/v5']], $this->servers($routes));
    }

    #[Test]
    public function aPlaceholderMountBecomesAServerVariableDefaultingToTheRouteDefault(): void
    {
        $routes = $this->import('default', '/{tenant}/api');
        $routes->addDefaults(['tenant' => 'acme']);

        self::assertSame(
            [['url' => '/{tenant}/api', 'variables' => ['tenant' => ['default' => 'acme']]]],
            $this->servers($routes),
        );
    }

    #[Test]
    public function aPlaceholderMountWithoutADefaultIsLeftOut(): void
    {
        $routes = $this->import('default', '/{tenant}/api', 'tenant');
        $routes->addCollection($this->import('default', '/api/v5', 'v5'));

        self::assertSame([['url' => '/api/v5']], $this->servers($routes));
    }

    #[Test]
    public function noRouterMeansNoServers(): void
    {
        self::assertSame([], (new MountServers())->forServer('default'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servers(RouteCollection $routes, string $server = 'default'): array
    {
        return \array_map(
            static fn(Server $server): array => $server->toArray(),
            MountServers::fromRoutes($routes, $server),
        );
    }

    /**
     * One server's routes as Symfony imports them, with their mounts recorded.
     */
    private function import(string $server, ?string $prefix = null, string $namePrefix = ''): RouteCollection
    {
        $loader = new JsonApiRouteLoader(
            routeDescriptorsByServer: [
                $server => [
                    'articles' => [
                        'uriType' => 'articles',
                        'isResource' => true,
                        'hasHydrator' => true,
                        'hasRelations' => false,
                        'operations' => ['FetchCollection', 'FetchOne'],
                        'tags' => [],
                        'responses' => [],
                    ],
                ],
            ],
        );

        $routes = $loader->load($server, JsonApiRouteLoader::ROUTE_TYPE);
        if ($prefix !== null) {
            $routes->addPrefix($prefix);
        }
        if ($namePrefix !== '') {
            $routes->addNamePrefix($namePrefix . '.');
        }

        return self::recorded($routes);
    }

    private static function recorded(RouteCollection $routes): RouteCollection
    {
        MountRecordingLoader::recordMounts($routes);

        return $routes;
    }
}
