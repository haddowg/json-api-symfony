<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\CategoryResource;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiProviderFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\ProductResource;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\RecalculatePrices;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\SummarizeCatalog;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The serve-time customisation witness kernel (bundle ADR 0131). The environment picks
 * the serve decorators:
 *  - `plain`: none, so the raw artifact is served;
 *  - `keyed`: host servers + feature gate (OpenAPI) and the feature gate (schemas), every
 *    one with a cache key;
 *  - `unkeyed`: the keyed set plus one decorator without a key, per document kind;
 *  - `objects`: the keyed set over a document holding a `DateTimeImmutable` value, which
 *    the serve artifact can't cache, with a logger keeping the warmer's warnings;
 *  - `objects_allowed`: the same, with `DateTimeImmutable` in `serve_allowed_classes`.
 *
 * Every environment configures `cache_control` and runs behind a stateful, lazy firewall
 * with sessions enabled, so the no-cookie guarantee is tested where a cookie could appear.
 * A listener reads the security token on every request, which marks the session as used,
 * so `cache_control` is also tested where Symfony's session listener would rewrite it.
 */
final class OpenApiServeTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string CACHE_CONTROL = 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400';

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-serve-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-serve-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/openapi-serve-log';
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
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);

        $container->extension('security', [
            'providers' => ['users' => ['memory' => null]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
        ]);

        $openapi = [
            'expose_in_prod' => true,
            'cache_control' => self::CACHE_CONTROL,
            'info' => ['title' => 'Catalog API', 'version' => '2.0.0'],
            'tags' => [
                ['name' => 'Catalog', 'description' => 'Products and catalog actions'],
            ],
        ];
        if ($this->environment === 'objects_allowed') {
            $openapi['serve_allowed_classes'] = [\DateTimeImmutable::class];
        }

        $container->extension('json_api', [
            'base_uri' => 'https://catalog.test',
            'version' => '1.1',
            'openapi' => $openapi,
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $objects = \in_array($this->environment, ['objects', 'objects_allowed'], true);

        $services->set('logger', $objects ? WarningLogger::class : \Psr\Log\NullLogger::class);

        $services->set(ProductResource::class);
        $services->set(CategoryResource::class);
        $services->set(RecalculatePrices::class);
        $services->set(SummarizeCatalog::class);

        $services->set(FeatureMarkingDecorator::class);
        $services->set(TokenReadingListener::class);

        if ($this->environment !== 'plain') {
            $services->set(HostServersDecorator::class);
            $services->set(FeatureGateDecorator::class);
            $services->set(FeatureGateSchemaDecorator::class);
        }
        if ($objects) {
            $services->set(ObjectExtensionDecorator::class);
        }
        if ($this->environment === 'unkeyed') {
            $services->set(UnkeyedDecorator::class);
            $services->set(UnkeyedSchemaDecorator::class);
        }

        $services->set('test.openapi.products_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([OpenApiProviderFactory::class, 'products'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.products_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([OpenApiProviderFactory::class, 'productsPersister'])
            ->args([service('test.openapi.products_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        $services->set('test.openapi.categories_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([OpenApiProviderFactory::class, 'categories'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.openapi.categories_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([OpenApiProviderFactory::class, 'categoriesPersister'])
            ->args([service('test.openapi.categories_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
