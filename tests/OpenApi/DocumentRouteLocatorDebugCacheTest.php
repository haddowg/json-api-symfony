<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi;

use haddowg\JsonApiBundle\Controller\OpenApiController;
use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentFormat;
use haddowg\JsonApiBundle\OpenApi\DocumentRouteLocator;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

final class DocumentRouteLocatorDebugCacheTest extends TestCase
{
    private string $dir;

    private int $loads = 0;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/jsonapi-route-scan-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/routes', 0o777, true);
        \file_put_contents($this->dir . '/routes/routes.yaml', 'routes');
        FlippableResource::$fresh = true;
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . '/json_api', $this->dir . '/routes'] as $dir) {
            foreach (\glob($dir . '/*') ?: [] as $file) {
                \unlink($file);
            }
            if (\is_dir($dir)) {
                \rmdir($dir);
            }
        }
        \rmdir($this->dir);
    }

    #[Test]
    public function aLaterLookupWithUnchangedRouteFilesDoesNotLoadTheRoutes(): void
    {
        $routes = $this->routes();

        self::assertSame(['route' => 'app_docs', 'parameters' => []], $this->locator($routes)->find('default'));
        self::assertSame(1, $this->loads);

        self::assertSame(['route' => 'app_docs', 'parameters' => []], $this->locator($routes)->find('default'));
        self::assertSame(1, $this->loads);
    }

    #[Test]
    public function touchingARouteFileScansAgain(): void
    {
        $routes = $this->routes();
        $this->locator($routes)->find('default');

        $routes->add('app_docs_moved', new Route('/api/v2/docs.json', ['_controller' => OpenApiController::class]));
        $routes->remove('app_docs');
        \touch($this->dir . '/routes/routes.yaml', \time() + 10);

        self::assertSame(['route' => 'app_docs_moved', 'parameters' => []], $this->locator($routes)->find('default'));
        self::assertSame(2, $this->loads);
    }

    #[Test]
    public function aResourceThatIsNoLongerFreshScansAgain(): void
    {
        $routes = $this->routes();
        $routes->addResource(new FlippableResource());
        $this->locator($routes)->find('default');

        FlippableResource::$fresh = false;
        $this->locator($routes)->find('default');

        self::assertSame(2, $this->loads);
    }

    #[Test]
    public function eachFormatAndServerSetHasItsOwnFile(): void
    {
        $routes = $this->routes();
        $this->locator($routes)->find('default');
        $this->locator($routes)->find('default', DocumentFormat::Yaml);
        $this->locator($routes, ['default', 'admin'])->find('default');

        self::assertSame(3, $this->loads);
        self::assertCount(3, \glob($this->dir . '/json_api/*.php') ?: []);
    }

    #[Test]
    public function rescanAlwaysLoadsTheRoutes(): void
    {
        $routes = $this->routes();
        $locator = $this->locator($routes);
        $locator->find('default');
        $locator->rescan();

        self::assertSame(2, $this->loads);
    }

    #[Test]
    public function withoutAFactoryDebugScansOncePerProcess(): void
    {
        $routes = $this->routes();
        $this->locator($routes, factory: false)->find('default');
        $this->locator($routes, factory: false)->find('default');

        self::assertSame(2, $this->loads);
    }

    #[Test]
    public function anUnwritableCacheDirOnlyCostsTheScan(): void
    {
        \file_put_contents($this->dir . '/json_api', 'not a directory');
        $routes = $this->routes();

        try {
            $first = $this->locator($routes)->find('default');
            $second = $this->locator($routes)->find('default');

            self::assertSame(['route' => 'app_docs', 'parameters' => []], $first);
            self::assertSame($first, $second);
            self::assertSame(2, $this->loads);
        } finally {
            \unlink($this->dir . '/json_api');
        }
    }

    private function routes(): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add('jsonapi.items.index', new Route('/api/items', [JsonApiRoute::MARKER => true, '_jsonapi_server' => 'default']));
        $routes->add('app_docs', new Route('/api/docs.json', ['_controller' => OpenApiController::class]));
        $routes->addResource(new FileResource($this->dir . '/routes/routes.yaml'));

        return $routes;
    }

    /**
     * @param list<string> $servers
     */
    private function locator(RouteCollection $routes, array $servers = ['default'], bool $factory = true): DocumentRouteLocator
    {
        $router = $this->createStub(Router::class);
        $router->method('getRouteCollection')->willReturnCallback(function () use ($routes): RouteCollection {
            ++$this->loads;

            return $routes;
        });
        $router->method('generate')->willReturnCallback(static fn(string $name, array $parameters = []): string => (new UrlGenerator($routes, new RequestContext()))->generate($name, $parameters));

        return new DocumentRouteLocator(
            $router,
            new ArtifactStore($this->dir),
            $servers,
            debug: true,
            configCacheFactory: $factory ? new ConfigCacheFactory(true) : null,
            cacheDir: $this->dir . '/json_api',
        );
    }
}

final class FlippableResource implements SelfCheckingResourceInterface
{
    public static bool $fresh = true;

    public function __toString(): string
    {
        return 'flippable';
    }

    public function isFresh(int $timestamp): bool
    {
        return self::$fresh;
    }
}
