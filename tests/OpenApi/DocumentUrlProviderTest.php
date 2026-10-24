<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi;

use haddowg\JsonApiBundle\Controller\OpenApiController;
use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentRouteLocator;
use haddowg\JsonApiBundle\OpenApi\DocumentUrlProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

final class DocumentUrlProviderTest extends TestCase
{
    #[Test]
    public function theDefaultIsTheServersDocumentRoutePathIncludingItsPrefix(): void
    {
        $urls = new DocumentUrlProvider($this->generator(withRoutes: true));

        self::assertSame('/api/docs.json', $urls->forServer());
        self::assertSame('/api/admin/docs.json', $urls->forServer('admin'));
    }

    #[Test]
    public function combinedModePointsEveryServerAtTheOneDocument(): void
    {
        $urls = new DocumentUrlProvider($this->generator(withRoutes: true), combined: true);

        self::assertSame('/api/docs.json', $urls->forServer('admin'));
    }

    #[Test]
    public function aConfiguredUrlIsUsedAsGivenWithTheServerNameSubstituted(): void
    {
        $url = 'https://docs.example.test/{server}.json';

        self::assertSame('https://docs.example.test/admin.json', (new DocumentUrlProvider($this->generator(withRoutes: false), configuredUrl: $url))->forServer('admin'));
        self::assertSame('https://docs.example.test/combined.json', (new DocumentUrlProvider($this->generator(withRoutes: false), combined: true, configuredUrl: $url))->forServer('admin'));
    }

    #[Test]
    public function thereIsNoUrlWhenTheDocumentIsNotServedOrDescribedbyIsOff(): void
    {
        self::assertNull((new DocumentUrlProvider($this->generator(withRoutes: false)))->forServer());
        self::assertNull((new DocumentUrlProvider($this->generator(withRoutes: true), enabled: false))->forServer());
        self::assertNull((new DocumentUrlProvider($this->generator(withRoutes: true), enabled: false, configuredUrl: 'https://docs.example.test/openapi.json'))->forServer());
    }

    #[Test]
    public function withoutTheBundlesRoutesItFindsTheAppsRouteToTheController(): void
    {
        $routes = new RouteCollection();
        $routes->add('app_docs', new Route('/api/docs.json', ['_controller' => OpenApiController::class . '::__invoke']));
        $routes->add('app_admin_docs', new Route('/api/v5/docs.json', ['_controller' => OpenApiController::class, 'server' => 'admin']));
        $routes->add('app_reports_docs', new Route('/api/reports/docs.json', ['_controller' => [OpenApiController::class, '__invoke'], 'server' => 'reports']));
        $routes->add('app_legacy_docs', new Route('/api/legacy/docs.json', ['_controller' => '\\' . OpenApiController::class . ':__invoke', 'server' => 'legacy']));
        $routes->add('elsewhere', new Route('/api/other.json', ['_controller' => 'App\\Other', 'server' => 'other']));

        $urls = $this->provider($routes, ['default', 'admin', 'reports', 'legacy', 'other']);

        self::assertSame('/api/docs.json', $urls->forServer());
        self::assertSame('/api/v5/docs.json', $urls->forServer('admin'));
        self::assertSame('/api/reports/docs.json', $urls->forServer('reports'));
        self::assertSame('/api/legacy/docs.json', $urls->forServer('legacy'));
        self::assertNull($urls->forServer('other'));
    }

    #[Test]
    public function aParametricRouteServesTheServersItsRequirementAccepts(): void
    {
        $routes = new RouteCollection();
        $routes->add('app_docs', new Route('/api/{server}/openapi.json', ['_controller' => OpenApiController::class], ['server' => 'v5|v6']));
        $routes->add('app_v6_docs', new Route('/api/v6/docs.json', ['_controller' => OpenApiController::class, 'server' => 'v6']));

        $urls = $this->provider($routes, ['default', 'v5', 'v6']);

        self::assertSame('/api/v5/openapi.json', $urls->forServer('v5'));
        // A route naming the server wins over a parametric one.
        self::assertSame('/api/v6/docs.json', $urls->forServer('v6'));
        self::assertNull($urls->forServer());
    }

    #[Test]
    public function combinedModeTakesAnyRouteToTheController(): void
    {
        $routes = new RouteCollection();
        $routes->add('app_docs', new Route('/api/openapi.json', ['_controller' => OpenApiController::class]));

        self::assertSame('/api/openapi.json', $this->provider($routes, ['default', 'admin'], combined: true)->forServer('admin'));
    }

    /**
     * @param list<string> $servers
     */
    private function provider(RouteCollection $routes, array $servers, bool $combined = false): DocumentUrlProvider
    {
        $router = $this->createStub(Router::class);
        $router->method('getRouteCollection')->willReturn($routes);
        $generator = new UrlGenerator($routes, new RequestContext());
        $router->method('generate')->willReturnCallback($generator->generate(...));

        $locator = new DocumentRouteLocator($router, new ArtifactStore(\sys_get_temp_dir()), $servers, $combined, debug: true);

        return new DocumentUrlProvider($generator, combined: $combined, routes: $locator);
    }

    private function generator(bool $withRoutes): UrlGenerator
    {
        $routes = new RouteCollection();
        if ($withRoutes) {
            $routes->add('jsonapi.openapi.default', new Route('/api/docs.json'));
            $routes->add('jsonapi.openapi.server', new Route('/api/{server}/docs.json'));
        }

        return new UrlGenerator($routes, new RequestContext());
    }
}
