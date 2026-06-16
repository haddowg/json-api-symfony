<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Http;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The response-header witness kernel **with** global `json_api.defaults` (bundle
 * ADR 0054): a default `max_age: 120` cache plus a default `Deprecation: true`.
 * `plainWidgets` (declares nothing) inherits both defaults; `cachedWidgets`
 * (declares `max_age: 60`) overrides the default cache — proving the resource-level
 * value merges over / wins against the global default.
 */
final class DefaultResponseHeadersTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/default-response-headers-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/default-response-headers-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/default-response-headers-log';
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
            'defaults' => [
                'cache_headers' => [
                    'max_age' => 120,
                    'public' => true,
                ],
                'deprecation' => true,
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Overrides the default cache (its own max_age: 60 wins); declares no
        // deprecation, so it still inherits the default Deprecation: true.
        $services->set(CachedWidgetResource::class);
        $services->set('test.cached_widgets_provider', InMemoryDataProvider::class)
            ->factory([HeaderWidgetFactory::class, 'createCachedProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.cached_widgets_persister', InMemoryDataPersister::class)
            ->factory([HeaderWidgetFactory::class, 'createCachedPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // Declares nothing — inherits the global default cache + deprecation.
        $services->set(PlainWidgetResource::class);
        $services->set('test.plain_widgets_provider', InMemoryDataProvider::class)
            ->factory([HeaderWidgetFactory::class, 'createPlainProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.plain_widgets_persister', InMemoryDataPersister::class)
            ->factory([HeaderWidgetFactory::class, 'createPlainPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
