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
 * The multi-server witness kernel (ADR 0034): a `default` server (top-level
 * base_uri) and a named `admin` server (its own base_uri). Three resources are
 * assigned via the attribute — `public-widgets` default-only, `admin-widgets`
 * admin-only, `shared-widgets` on both — and the route loader is imported once per
 * server, the admin import carrying an `/admin` prefix. So the default surface
 * mounts at the root, the admin surface under `/admin`, and the shared type appears
 * on both, each route resolving its own Server (asserted by base_uri in the links).
 */
final class MultiServerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string DEFAULT_BASE_URI = 'https://public.test';

    public const string ADMIN_BASE_URI = 'https://admin.test';

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/multi-server-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/multi-server-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/multi-server-log';
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
            'base_uri' => self::DEFAULT_BASE_URI,
            'version' => '1.1',
            'servers' => [
                'admin' => ['base_uri' => self::ADMIN_BASE_URI],
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
        // The bare import is the `default` server (unprefixed); the `admin` import
        // names the server and mounts its routes under `/admin`. Prefix/host stay in
        // the application's routing config, exactly where Symfony users expect them.
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('admin', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/admin');
    }
}
