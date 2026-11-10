<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `settings` provider, seeded with one setting so a PATCH has a
 * target, and a persister sharing its store.
 */
final class SettingFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'settings',
            ['1' => new Setting('1', answer: 'yes', currency: 'GBP')],
            static function (object $item): string {
                \assert($item instanceof Setting);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Setting);

                $item->id = $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('settings', $provider->store(), static fn(): Setting => new Setting());
    }
}
