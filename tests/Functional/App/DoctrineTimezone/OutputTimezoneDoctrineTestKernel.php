<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * The Doctrine half of the output-timezone conformance pair, configured like
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\OutputTimezoneInMemoryTestKernel}:
 * UTC on the default server, Asia/Tokyo on `tokyo`, no conversion on `raw`. The SQLite
 * database is seeded by {@see \haddowg\JsonApiBundle\Tests\Functional\DoctrineOutputTimezoneTest}.
 */
final class OutputTimezoneDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new ZenstruckFoundryBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/output-timezone-doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/output-timezone-doctrine-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/output-timezone-doctrine-log';
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

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiTimezoneTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone',
                    'is_bundle' => false,
                ],
            ],
        ];

        // Mirror DoctrineJsonApiTestKernel: native lazy objects only on the deps
        // stack that dropped var-exporter's LazyGhostTrait. Only this directory is
        // mapped, so no other kernel needs the paris_datetime type.
        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                'types' => [ParisDateTimeType::NAME => ParisDateTimeType::class],
            ],
            'orm' => $orm,
        ]);

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

        $services->set(DoctrineGigResource::class);
        $services->set(DoctrineVenueResource::class);
        $services->set(DoctrinePerformerResource::class);
        $services->set(DoctrineGigListingResource::class);
        $services->set(DoctrineRawGigResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('tokyo', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/tokyo');
        $routes->import('raw', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/raw');
    }
}
