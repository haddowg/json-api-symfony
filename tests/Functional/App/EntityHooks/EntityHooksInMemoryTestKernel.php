<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityHooks;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class EntityHooksInMemoryTestKernel extends EntityHooksDoctrineTestKernel
{
    protected function doctrine(): bool
    {
        return false;
    }

    protected function name(): string
    {
        return 'entity-hooks-inmemory';
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $services->set(HookedNoteResource::class);

        $services->set('test.hooked_notes_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.hooked_notes_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->args([service('test.hooked_notes_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    public static function provider(): InMemoryDataProvider
    {
        $note = new HookedNote('Seeded');
        $note->id = 1;

        return new InMemoryDataProvider(
            'hooked-notes',
            ['1' => $note],
            static fn(object $item): string => $item instanceof HookedNote && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof HookedNote);
                $item->id = (int) $id;
            },
        );
    }

    public static function persister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        // The factory a create would fall back to: a note built without its constructor.
        $bare = static fn(): HookedNote => (new \ReflectionClass(HookedNote::class))->newInstanceWithoutConstructor();

        return new InMemoryDataPersister('hooked-notes', $provider->store(), $bare);
    }
}
