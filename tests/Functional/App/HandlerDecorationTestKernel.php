<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Operation\CrudOperationHandler;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BookResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The handler-override witness kernel (ADR 0028): a single writable in-memory
 * `book` resource plus an {@see InterceptingHandler} registered as a Symfony
 * **decorator** of the generic {@see CrudOperationHandler}. The `ServerFactory`
 * resolves the handler by service id (`service(CrudOperationHandler::class)`), so
 * the decoration is transparently picked up — the engine the server dispatches to
 * is the decorator, which wraps the generic engine as its inner.
 *
 * The decoration is declared with the `#[AsDecorator(CrudOperationHandler::class)]`
 * attribute on the fixture, so the autoconfiguring service definition registers it
 * with no explicit `->decorate(...)` call. `b1` is seeded so a single-resource
 * fetch is interceptable and a collection fetch is delegatable.
 */
final class HandlerDecorationTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/handler-decoration-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/handler-decoration-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/handler-decoration-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(BookResource::class);

        $services->set('test.books_provider', InMemoryDataProvider::class)
            ->factory([BookFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.books_persister', InMemoryDataPersister::class)
            ->factory([BookFactory::class, 'createPersister'])
            ->args([service('test.books_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // The decorator of the generic CRUD engine: autoconfigured, so the
        // #[AsDecorator(CrudOperationHandler::class)] attribute on the fixture wires
        // it as a decoration of that service id (no explicit ->decorate(...) call).
        $services->set(InterceptingHandler::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
