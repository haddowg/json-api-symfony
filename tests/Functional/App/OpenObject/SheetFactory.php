<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenObject;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `sheets` provider, seeded with one sheet so a PATCH has a
 * target, and a persister sharing its store.
 */
final class SheetFactory
{
    public const array SEED_ROWS = [['Key' => 'RFP-1', 'Vendor' => 'Acme']];
    public const array SEED_LABELS = ['tone' => 'warm', 'size' => 'large'];
    public const array SEED_SETTINGS = ['theme' => 'dark', 'limits' => ['daily' => 10]];
    public const array SEED_METRICS = ['views' => 3];

    public static function createProvider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'sheets',
            ['1' => new Sheet('1', 'Seed', self::SEED_ROWS, self::SEED_LABELS, self::SEED_SETTINGS, self::SEED_METRICS)],
            static function (object $item): string {
                \assert($item instanceof Sheet);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Sheet);

                $item->id = $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('sheets', $provider->store(), static fn(): Sheet => new Sheet());
    }
}
