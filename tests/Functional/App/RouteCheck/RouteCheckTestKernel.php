<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RouteCheck;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\BookFactory;
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
 * A JSON:API server imported under `/api` beside a plain application route, with a
 * subscriber that asks {@see \haddowg\JsonApiBundle\Routing\JsonApiRoute::matches()}
 * before and after routing.
 */
final class RouteCheckTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/route-check-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/route-check-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/route-check-log';
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
        $services->set(RouteCheckSubscriber::class);
        $services->set(PlainController::class)->public()->tag('controller.service_arguments');

        $services->set('test.books_provider', InMemoryDataProvider::class)
            ->factory([BookFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.books_persister', InMemoryDataPersister::class)
            ->factory([BookFactory::class, 'createPersister'])
            ->args([service('test.books_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/api');
        $routes->add('plain', '/plain')->controller(PlainController::class);
    }
}
