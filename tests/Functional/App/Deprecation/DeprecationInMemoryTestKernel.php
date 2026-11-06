<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class DeprecationInMemoryTestKernel extends DeprecationDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'deprecation-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryBulletinResource::class);
        $services->set(InMemoryMemoResource::class);
        $services->set(InMemoryPamphletResource::class);
        $services->set(InMemoryLeafletResource::class);
        $services->set(InMemoryNoticeResource::class);

        foreach (['bulletins', 'memos', 'pamphlets', 'leaflets', 'notices'] as $type) {
            $services->set('test.' . $type . '_provider', InMemoryDataProvider::class)
                ->factory([self::class, 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        }

        $services->set('test.bulletins_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->args([service('test.bulletins_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    public static function provider(string $type): InMemoryDataProvider
    {
        $first = new Bulletin(1, 'First', 'A1');
        $items = ['1' => $first];
        if ($type === 'bulletins') {
            $second = new Bulletin(2, 'Second', 'B2');
            $second->parent = $first;
            $items['2'] = $second;
        }

        return new InMemoryDataProvider(
            $type,
            $items,
            static fn(object $item): string => $item instanceof Bulletin && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Bulletin);
                $item->id = (int) $id;
            },
        );
    }

    public static function persister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('bulletins', $provider->store(), static fn(): Bulletin => new Bulletin());
    }
}
