<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Logging\Middleware as DbalLoggingMiddleware;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\BaseBrokenThrResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\ThroughInKernelConfig;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves the lead graph from SQLite through the Doctrine provider, with an extension
 * hiding the hidden advertiser and every statement counted. With {@see $broken} it also
 * registers {@see DoctrineBrokenThrResource}.
 */
final class ThroughDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static ?string $broken = null;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        return ThroughInKernelConfig::directory('through-relations-doctrine-app');
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-relations-doctrine-cache/' . (self::$broken ?? 'ok') . '-' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-relations-doctrine-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        ThroughInKernelConfig::apply($container, $builder);

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiThroughTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine',
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

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(QueryCountingLogger::class)->public();
        $services->set(DbalLoggingMiddleware::class)
            ->args([new Reference(QueryCountingLogger::class)])
            ->tag('doctrine.middleware');

        $services->set(HiddenThrAdvertiserExtension::class);
        $services->set(DoctrineThrAdvertiserResource::class);
        $services->set(DoctrineThrTemplateResource::class);
        $services->set(DoctrineThrCampaignResource::class);
        $services->set(DoctrineThrLeadResource::class);

        if (self::$broken !== null) {
            BaseBrokenThrResource::$declaration = self::$broken;
            $services->set(DoctrineBrokenThrResource::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
