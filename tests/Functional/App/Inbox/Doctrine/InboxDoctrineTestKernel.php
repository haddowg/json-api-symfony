<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\ClosureSubjectResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\TallyInboxItems;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\ThroughInKernelConfig;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves the inbox from SQLite through the Doctrine provider. With
 * {@see $closureSubject} it also registers {@see ClosureSubjectResource}, whose filter
 * the Doctrine provider cannot query.
 */
final class InboxDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static bool $closureSubject = false;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        return ThroughInKernelConfig::directory('inbox-doctrine-app');
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/inbox-doctrine-cache/' . (self::$closureSubject ? 'closure-' : '') . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/inbox-doctrine-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        ThroughInKernelConfig::apply($container, $builder);

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiInboxTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine',
                    'is_bundle' => false,
                ],
            ],
        ];

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

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(DoctrineRfpResource::class);
        $services->set(DoctrineInboxCampaignResource::class);
        $services->set(DoctrineInboxFolderResource::class);
        $services->set(DoctrineInboxItemResource::class);
        $services->set(TallyInboxItems::class);

        if (self::$closureSubject) {
            $services->set(ClosureSubjectResource::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
