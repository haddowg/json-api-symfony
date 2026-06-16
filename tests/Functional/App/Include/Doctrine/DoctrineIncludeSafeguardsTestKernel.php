<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

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
 * The Doctrine include-safeguards kernel (bundle ADR 0037): FrameworkBundle +
 * DoctrineBundle + JsonApiBundle over an in-memory SQLite database, mapping ONLY
 * this slice's `Include\Doctrine` entities so its `nodes`/`tags` types do not
 * collide with the article kernel's. It leaves `json_api.max_include_depth` UNSET,
 * so the bundle's opinionated default of `3` governs, and the Doctrine batch
 * include-preloader (when `shipmonk/doctrine-entity-preloader` is installed) walks
 * the same effective tree — so the safeguards are witnessed against the real
 * Doctrine read + preload path, not only the in-memory accessor.
 */
final class DoctrineIncludeSafeguardsTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/include-safeguards-doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-safeguards-doctrine-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/include-safeguards-doctrine-log';
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
                'JsonApiIncludeApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine',
                    'is_bundle' => false,
                ],
            ],
        ];

        // Symfony 8 / Doctrine native lazy objects: set conditionally, exactly as
        // the article Doctrine kernel does (the lowest supported deps still ship
        // var-exporter's LazyGhostTrait and predate the option).
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

        // No max_include_depth key: the bundle default of 3 governs.
        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(DoctrineNodeResource::class);
        $services->set(DoctrineTagResource::class);
        $services->set(DoctrineRootResource::class);
        $services->set(DoctrineCapResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
