<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class EntityConstraintsInMemoryTestKernel extends EntityConstraintsDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'entity-constraints-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(BrandResource::class);

        $services->set('test.brands_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.brands_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->args([service('test.brands_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    public static function provider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'brands',
            [],
            static fn(object $item): string => $item instanceof Brand && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Brand);
                $item->id = (int) $id;
            },
        );
    }

    public static function persister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('brands', $provider->store(), static fn(): Brand => new Brand());
    }
}
