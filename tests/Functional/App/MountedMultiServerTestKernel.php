<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\AdminWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\PublicWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\SharedWidgetResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The multi-server widgets with an empty `base_uri` on both servers: the `default`
 * server imported under `/api/v5` and the named `admin` server under `/admin`. Each
 * route's links carry its own server's mount (bundle ADR 0121).
 */
final class MountedMultiServerTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/mounted-multi-server-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/mounted-multi-server-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/mounted-multi-server-log';
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
            'base_uri' => '',
            'version' => '1.1',
            'servers' => [
                'admin' => [],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(PublicWidgetResource::class);
        $services->set(AdminWidgetResource::class);
        $services->set(SharedWidgetResource::class);

        $services->set('test.public_provider', InMemoryDataProvider::class)
            ->factory([MultiServerWidgetFactory::class, 'createPublic'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.admin_provider', InMemoryDataProvider::class)
            ->factory([MultiServerWidgetFactory::class, 'createAdmin'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.shared_provider', InMemoryDataProvider::class)
            ->factory([MultiServerWidgetFactory::class, 'createShared'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.public_persister', InMemoryDataPersister::class)
            ->factory([MultiServerWidgetFactory::class, 'persister'])
            ->args(['public-widgets', service('test.public_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.admin_persister', InMemoryDataPersister::class)
            ->factory([MultiServerWidgetFactory::class, 'persister'])
            ->args(['admin-widgets', service('test.admin_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.shared_persister', InMemoryDataPersister::class)
            ->factory([MultiServerWidgetFactory::class, 'persister'])
            ->args(['shared-widgets', service('test.shared_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('default', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/api/v5');
        $routes->import('admin', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/admin');
    }
}
