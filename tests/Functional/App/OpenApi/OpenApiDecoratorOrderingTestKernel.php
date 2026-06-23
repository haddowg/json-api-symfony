<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Ordering witness for the decorator seam (design §5, D7 — bundle ADR 0080): registers
 * two {@see PriorityDecorator}s at different priorities, both overwriting the document
 * title outright, to prove the documented contract — the **highest-priority** decorator
 * is applied LAST and gets the final word (the bundle's highest-wins convention).
 */
final class OpenApiDecoratorOrderingTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const HIGH_PRIORITY_TITLE = 'High-priority wins';

    public const LOW_PRIORITY_TITLE = 'Low-priority loses';

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-ordering-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-ordering-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-ordering-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://catalog.test',
            'version' => '1.1',
            'openapi' => [
                'expose_in_prod' => true,
                'info' => [
                    'title' => 'Original title',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', \Psr\Log\NullLogger::class);

        $services->set(ProductResource::class);
        $services->set(CategoryResource::class);

        // Two decorators on the OpenAPI factory tag at distinct priorities, each
        // overwriting the title. The contract: highest priority applied LAST → wins.
        // autoconfigure(false) so the explicit priority tag is the only one (otherwise
        // autoconfiguration would add a second, priority-0 tag entry).
        $services->set('test.openapi.decorator.low', PriorityDecorator::class)
            ->autoconfigure(false)
            ->args(['$label' => self::LOW_PRIORITY_TITLE])
            ->tag(JsonApiBundle::OPENAPI_FACTORY_TAG, ['priority' => -10]);

        $services->set('test.openapi.decorator.high', PriorityDecorator::class)
            ->autoconfigure(false)
            ->args(['$label' => self::HIGH_PRIORITY_TITLE])
            ->tag(JsonApiBundle::OPENAPI_FACTORY_TAG, ['priority' => 10]);

        $services->set('test.openapi.ordering.products_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([OpenApiProviderFactory::class, 'products'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.ordering.products_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([OpenApiProviderFactory::class, 'productsPersister'])
            ->args([service('test.openapi.ordering.products_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.openapi.ordering.categories_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([OpenApiProviderFactory::class, 'categories'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.ordering.categories_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([OpenApiProviderFactory::class, 'categoriesPersister'])
            ->args([service('test.openapi.ordering.categories_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
