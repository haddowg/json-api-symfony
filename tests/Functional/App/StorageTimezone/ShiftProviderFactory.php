<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class ShiftProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        $shifts = [];
        foreach (ShiftFixtures::data() as $id => $row) {
            $shifts[(string) $id] = new Shift((int) $id, ShiftFixtures::at($row['startsAt']), ShiftFixtures::at($row['day']));
        }

        return new InMemoryDataProvider(
            'shifts',
            $shifts,
            static function (object $item): string {
                \assert($item instanceof Shift);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Shift);

                $item->id = (int) $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('shifts', $provider->store(), static fn(): Shift => new Shift());
    }
}
