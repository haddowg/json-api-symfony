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
 * The response-header witness kernel **without** global `json_api.defaults` (bundle
 * ADR 0054): a `cachedWidgets` resource declaring cacheHeaders, a
 * `deprecatedWidgets` resource declaring deprecation/sunset, and a `plainWidgets`
 * control declaring nothing — so its reads get no headers (unchanged behaviour).
 * The companion {@see DefaultResponseHeadersTestKernel} adds the global defaults.
 */
final class ResponseHeadersTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/response-headers-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/response-headers-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/response-headers-log';
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

        $services->set(CachedWidgetResource::class);
        $services->set('test.cached_widgets_provider', InMemoryDataProvider::class)
            ->factory([HeaderWidgetFactory::class, 'createCachedProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.cached_widgets_persister', InMemoryDataPersister::class)
            ->factory([HeaderWidgetFactory::class, 'createCachedPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set(DeprecatedWidgetResource::class);
        $services->set('test.deprecated_widgets_provider', InMemoryDataProvider::class)
            ->factory([HeaderWidgetFactory::class, 'createDeprecatedProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.deprecated_widgets_persister', InMemoryDataPersister::class)
            ->factory([HeaderWidgetFactory::class, 'createDeprecatedPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

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
