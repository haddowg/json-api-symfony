<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Security;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the shared in-memory store for the `securedWidgets` authorization witness: a
 * provider seeded with one row + a persister over the same store, so a denied write
 * leaves the (immediately readable) store unchanged.
 */
final class InMemorySecuredWidgetFactory
{
    private static ?InMemoryDataProvider $provider = null;

    public static function reset(): void
    {
        self::$provider = null;
    }

    public static function createProvider(): InMemoryDataProvider
    {
        return self::provider();
    }

    public static function createPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'securedWidgets',
            self::provider()->store(),
            static fn(): InMemorySecuredWidget => new InMemorySecuredWidget(),
        );
    }

    private static function provider(): InMemoryDataProvider
    {
        return self::$provider ??= new InMemoryDataProvider(
            'securedWidgets',
            ['1' => new InMemorySecuredWidget(1, 'seeded')],
            static function (object $item): string {
                \assert($item instanceof InMemorySecuredWidget);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof InMemorySecuredWidget);

                $item->id = (int) $id;
            },
        );
    }
}
