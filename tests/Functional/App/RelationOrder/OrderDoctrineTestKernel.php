<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Logging\Middleware as DbalLoggingMiddleware;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class OrderDoctrineTestKernel extends AbstractOrderKernel
{
    protected function dataBundles(): iterable
    {
        return [new DoctrineBundle()];
    }

    protected function name(): string
    {
        return 'relation-order-doctrine';
    }

    protected function shelfResource(): string
    {
        return PivotOrderShelfResource::class;
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiRelationOrderTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => __NAMESPACE__,
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

        $services->set(QueryCountingLogger::class)->public();
        $services->set(DbalLoggingMiddleware::class)
            ->args([service(QueryCountingLogger::class)])
            ->tag('doctrine.middleware');

        // Map each resource to its entity, so the Doctrine provider serves it.
        $services->set(PivotOrderShelfResource::class)->tag(JsonApiBundle::RESOURCE_TAG, ['entity' => OrderShelf::class]);
        $services->set(OrderBookResource::class)->tag(JsonApiBundle::RESOURCE_TAG, ['entity' => OrderBook::class]);
        $services->set(OrderMagazineResource::class)->tag(JsonApiBundle::RESOURCE_TAG, ['entity' => OrderMagazine::class]);
    }
}
