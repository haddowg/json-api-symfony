<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Logging\Middleware as DbalLoggingMiddleware;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * The Doctrine half of the flattened-attribute (`on()`) conformance pair (bundle ADR
 * 0085): {@see DoctrineFlattenBookResource} (flattened single-hop `authorName`,
 * multi-hop `authorCountry` (`on('author.country')`), computed `display`) mapped to
 * {@see FlattenBookEntity}, with the related {@see DoctrineFlattenAuthorResource} /
 * {@see DoctrineFlattenCountryResource} / {@see DoctrineFlattenPublisherResource}
 * mapped to their entities, served by the bundle's `-128` fallback Doctrine
 * provider/persister over an in-memory SQLite database seeded by the test
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\SeedsFlatten}, Foundry).
 *
 * It also registers a DBAL {@see DbalLoggingMiddleware} backed by a
 * {@see QueryCountingLogger}, so the budget witness can prove the per-row N+1 from
 * the flattened read collapses to ONE batched `WHERE id IN` load.
 */
final class FlattenDoctrineTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/flatten-doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/flatten-doctrine-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/flatten-doctrine-log';
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
                'JsonApiTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Doctrine',
                    'is_bundle' => false,
                ],
            ],
        ];

        // Symfony 8 removed var-exporter's LazyGhostTrait, and Doctrine ORM then
        // requires PHP >= 8.4 native lazy objects for its proxies; set conditionally
        // (the lowest supported deps, Symfony 6.4, still ship LazyGhost).
        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'orm' => $orm,
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // The query-counting logger (public so the budget witness reads its captured
        // SQL) and the DBAL logging middleware it backs.
        $services->set(QueryCountingLogger::class)->public();
        $services->set(DbalLoggingMiddleware::class)
            ->args([new Reference(QueryCountingLogger::class)])
            ->tag('doctrine.middleware');

        $services->set(DoctrineFlattenBookResource::class);
        $services->set(DoctrineFlattenAuthorResource::class);
        $services->set(DoctrineFlattenPublisherResource::class);
        $services->set(DoctrineFlattenCountryResource::class);

        // Expose the eager-load batcher publicly so the budget witness can toggle it
        // off — proving the per-row N+1 from the flattened read returns when batching
        // is disabled, and is collapsed to ONE IN(…) load when it is on.
        $services->alias('test.flatten_include_batcher', \haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher::class)
            ->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
