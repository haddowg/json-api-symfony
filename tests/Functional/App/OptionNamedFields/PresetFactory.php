<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OptionNamedFields;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `presets` provider, seeded with one preset so a PATCH has a
 * target, and a persister sharing its store.
 */
final class PresetFactory
{
    public const array SEED_OPTIONS = ['groups' => 'seed', 'fields' => 'seed', 'payload' => 'seed', 'allowMissingFields' => 'seed'];

    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'presets',
            ['1' => new Preset('1', 'seed', 'seed', 'seed', self::SEED_OPTIONS)],
            static function (object $item): string {
                \assert($item instanceof Preset);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Preset);

                $item->id = $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('presets', $provider->store(), static fn(): Preset => new Preset());
    }
}
