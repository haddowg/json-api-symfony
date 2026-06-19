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

/**
 * The single-server OpenAPI document witness kernel (Slice 4 stage B): two resources
 * (`products` — explicitly `Catalog`-tagged, enum attribute, filters/sorts, a to-one
 * + to-many relation, a security expression on read/create; `categories` — untagged,
 * humanized-default tag), a collection-scope custom action, and the `json_api.openapi`
 * config (a configured `Catalog` tag definition + a `bearer` security scheme + default
 * requirement). It imports the docs routes (`jsonapi_openapi`), so `GET /docs.json`
 * serves the generated document — which the suite meta-validates and structurally
 * asserts against this exact surface.
 *
 * `expose_in_prod: true` so the docs route is registered even though the functional
 * kernel boots `debug=false` (the expose gate is exercised separately by
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiExposeGateTestKernel}).
 */
final class OpenApiTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-log';
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
                    'title' => 'Catalog API',
                    'version' => '2.0.0',
                    'description' => 'A JSON:API catalog surface.',
                ],
                'security' => [
                    'schemes' => [
                        'bearer' => ['type' => 'bearer', 'bearerFormat' => 'JWT'],
                    ],
                    'default_requirement' => ['bearer'],
                ],
                'tags' => [
                    ['name' => 'Catalog', 'description' => 'Products and catalog actions'],
                ],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Silence the test kernel's 500-log noise (memory note).
        $services->set('logger', \Psr\Log\NullLogger::class);

        $services->set(ProductResource::class);
        $services->set(CategoryResource::class);
        $services->set(RecalculatePrices::class);

        $services->set('test.openapi.products_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([OpenApiProviderFactory::class, 'products'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.categories_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([OpenApiProviderFactory::class, 'categories'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
