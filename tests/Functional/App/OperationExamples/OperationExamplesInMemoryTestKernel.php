<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class OperationExamplesInMemoryTestKernel extends OperationExamplesDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'operation-examples-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(InMemoryNoteResource::class);
        $services->set(InMemoryScrapResource::class);

        $services->set('test.notes_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider'])
            ->args(['notes'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.notes_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->args([service('test.notes_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        $services->set('test.scraps_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider'])
            ->args(['scraps'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }

    public static function provider(string $type): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            $type,
            ['1' => self::seed()],
            static fn(object $item): string => $item instanceof Note && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Note);
                $item->id = (int) $id;
            },
        );
    }

    public static function persister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('notes', $provider->store(), static fn(): Note => new Note());
    }

    public static function seed(): Note
    {
        $note = new Note();
        $note->id = 1;
        $note->title = 'Seeded';
        $note->rating = 3;

        return $note;
    }
}
