<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\LeafletResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\StickerResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory half of the strict-sparse-fieldset-member conformance pair: the
 * {@see LeafletResource} (a known field, an always-hidden field, a non-sparse field
 * and a to-one to {@see StickerResource}) over the read-only in-memory graph
 * ({@see StrictFieldsetFactory}). `json_api.strict_query_parameters` is left at its
 * default (on), so an unknown `fields[type]` member is rejected. Distinct
 * cache/project dirs keep its compiled container from colliding with the other
 * kernels'.
 */
final class StrictFieldsetInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/strict-fieldset-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/strict-fieldset-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/strict-fieldset-log';
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

        $services->set(LeafletResource::class);
        $services->set(StickerResource::class);

        $services->set('test.leaflets_provider', InMemoryDataProvider::class)
            ->factory([StrictFieldsetFactory::class, 'createLeaflets'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.stickers_provider', InMemoryDataProvider::class)
            ->factory([StrictFieldsetFactory::class, 'createStickers'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
