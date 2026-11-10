<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class OrderInMemoryTestKernel extends AbstractOrderKernel
{
    /**
     * The shelves resource the next boot registers. The in-memory provider holds no
     * pivot data, so registering {@see PivotOrderShelfResource} fails the build.
     *
     * @var class-string<OrderShelfResource>
     */
    public static string $shelves = OrderShelfResource::class;

    protected function dataBundles(): iterable
    {
        return [];
    }

    protected function name(): string
    {
        return 'relation-order-inmemory' . (self::$shelves === OrderShelfResource::class ? '' : '-' . \md5(self::$shelves));
    }

    protected function shelfResource(): string
    {
        return self::$shelves;
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(OrderFixtures::class);

        foreach (['shelves', 'books'] as $type) {
            $services->set('test.order_provider.' . $type, InMemoryDataProvider::class)
                ->factory([service(OrderFixtures::class), 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

            $services->set('test.order_persister.' . $type, InMemoryDataPersister::class)
                ->factory([service(OrderFixtures::class), 'persister'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }
}
