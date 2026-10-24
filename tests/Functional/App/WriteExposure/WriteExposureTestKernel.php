<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\Controller\JsonApiController;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Doctrine entity-mapped types whose operations exclude Update (`wx-shelves` is
 * read-only, `wx-bins` lists its operations without Update), so the generic
 * Doctrine persister could write them if a request reached it. Seeded by
 * {@see \haddowg\JsonApiBundle\Tests\Functional\RelationshipWriteExposureTest}.
 */
final class WriteExposureTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/write-exposure-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/write-exposure-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/write-exposure-log';
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

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiWriteExposureTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure',
                    'is_bundle' => false,
                ],
            ],
        ];

        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => $orm,
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        // The router-native 405s are logged by the framework's ErrorListener; keep
        // them out of the test output.
        $builder->register('logger', NullLogger::class);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(WxPersonResource::class);
        $services->set(WxShelfResource::class);
        $services->set(WxBinResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);

        // A hand-written route onto the read-only type's relationship endpoint, so a
        // request reaches the handler without the loader's operation gating.
        $routes->add('custom.wx-shelves.relationship.update', '/custom/wx-shelves/{id}/relationships/{relationship}')
            ->controller(JsonApiController::class)
            ->defaults([
                TargetResolver::TYPE_ATTRIBUTE => 'wx-shelves',
                TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE => true,
                '_jsonapi_server' => 'default',
                JsonApiRoute::MARKER => true,
            ])
            ->methods(['PATCH', 'POST', 'DELETE']);
    }
}
