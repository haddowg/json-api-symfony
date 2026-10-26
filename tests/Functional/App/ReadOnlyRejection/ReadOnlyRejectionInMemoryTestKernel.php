<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyRejection;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class ReadOnlyRejectionInMemoryTestKernel extends ReadOnlyRejectionDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'read-only-rejection-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryMemoResource::class);

        $services->set('test.memos_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.memos_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->args([service('test.memos_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    public static function provider(): InMemoryDataProvider
    {
        $memo = new Memo();
        $memo->id = 1;
        $memo->body = 'Seeded';

        return new InMemoryDataProvider(
            'memos',
            ['1' => $memo],
            static fn(object $item): string => $item instanceof Memo && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Memo);
                $item->id = (int) $id;
            },
        );
    }

    public static function persister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('memos', $provider->store(), static fn(): Memo => new Memo());
    }
}
