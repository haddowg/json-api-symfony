<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DateMessage;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class BookingProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'bookings',
            [
                '1' => new Booking(1, new \DateTimeImmutable('2026-10-01T09:00:00Z'), null, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-12-24'), breakAt: new \DateTimeImmutable('12:30:00')),
                '2' => new Booking(2, new \DateTimeImmutable('2026-10-02T15:00:00Z'), null, new \DateTimeImmutable('2026-10-02'), new \DateTimeImmutable('2026-12-31'), breakAt: new \DateTimeImmutable('14:00:00')),
            ],
            static function (object $item): string {
                \assert($item instanceof Booking);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Booking);

                $item->id = (int) $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('bookings', $provider->store(), static fn(): Booking => new Booking());
    }
}
