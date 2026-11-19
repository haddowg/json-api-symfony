<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class PlainPriceProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'plainPrices',
            [],
            static function (object $item): string {
                \assert($item instanceof PlainPrice);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof PlainPrice);

                $item->id = (int) $id;
            },
        );
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('plainPrices', $provider->store(), static fn(): PlainPrice => new PlainPrice());
    }
}
