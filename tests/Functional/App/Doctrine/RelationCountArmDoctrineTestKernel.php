<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Query\DoctrineRelationCountFilterArm;
use haddowg\JsonApiBundle\Tests\Functional\App\Query\DoctrineRelationCountSortArm;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * The Doctrine half of the extensible-handler-seam conformance pair: serves the
 * {@see DoctrineRelationCountArticleResource} (custom count filter + count sort)
 * mapped to {@see ArticleEntity}, with the two **autoconfigured** Doctrine arms
 * ({@see DoctrineRelationCountFilterArm} / {@see DoctrineRelationCountSortArm})
 * pushing the custom value objects down to `SIZE(...)` DQL. The database is seeded
 * with the canonical relationship graph by the test
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\SeedsDoctrineRelationships}).
 */
final class RelationCountArmDoctrineTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/relation-count-arm-doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/relation-count-arm-doctrine-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/relation-count-arm-doctrine-log';
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

        $services->set(DoctrineRelationCountArticleResource::class);
        $services->set(DoctrineAuthorResource::class);
        $services->set(DoctrineCommentResource::class);

        // The demonstrator arms — autoconfigured by their interfaces onto the
        // DOCTRINE_FILTER_ARM_TAG / DOCTRINE_SORT_ARM_TAG and wired into the provider.
        $services->set(DoctrineRelationCountFilterArm::class);
        $services->set(DoctrineRelationCountSortArm::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
