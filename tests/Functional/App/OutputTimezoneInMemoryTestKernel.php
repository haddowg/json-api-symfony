<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\GigListingResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\GigResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\RawGigResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\SharedGigResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\VenueResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory half of the output-timezone conformance pair. The default server
 * renders in UTC, `tokyo` (under `/tokyo`) in Asia/Tokyo, and `raw` (under `/raw`)
 * sets an empty string to turn conversion off. Every fixture value is in Europe/Paris.
 */
final class OutputTimezoneInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/output-timezone-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/output-timezone-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/output-timezone-log';
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
        PinnedConfigDefaults::apply($container, $builder);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'output_timezone' => 'UTC',
            'servers' => [
                'tokyo' => ['base_uri' => 'https://example.test/tokyo', 'output_timezone' => 'Asia/Tokyo'],
                'raw' => ['base_uri' => 'https://example.test/raw', 'output_timezone' => ''],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(GigResource::class);
        $services->set(VenueResource::class);
        $services->set(GigListingResource::class);
        $services->set(RawGigResource::class);
        $services->set(SharedGigResource::class);

        $services->set('test.gigs_provider', InMemoryDataProvider::class)
            ->factory([OutputTimezoneFixtures::class, 'createGigs'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.venues_provider', InMemoryDataProvider::class)
            ->factory([OutputTimezoneFixtures::class, 'createVenues'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.gig_listings_provider', InMemoryDataProvider::class)
            ->factory([OutputTimezoneFixtures::class, 'createGigListings'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.raw_gigs_provider', InMemoryDataProvider::class)
            ->factory([OutputTimezoneFixtures::class, 'createRawGigs'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.shared_gigs_provider', InMemoryDataProvider::class)
            ->factory([OutputTimezoneFixtures::class, 'createSharedGigs'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('tokyo', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/tokyo');
        $routes->import('raw', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/raw');
    }
}
