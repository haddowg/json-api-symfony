<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Http;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires a shared in-memory store per response-header witness type (bundle ADR
 * 0054): a provider seeded with one row + a persister over the same store, so a
 * write is immediately readable and a missing id is a clean `404` (the
 * not-cached-on-error witness). One provider instance per type is memoized so the
 * provider and its persister share a store within a boot.
 */
final class HeaderWidgetFactory
{
    /** @var array<string, InMemoryDataProvider> */
    private static array $providers = [];

    public static function reset(): void
    {
        self::$providers = [];
    }

    public static function createCachedProvider(): InMemoryDataProvider
    {
        return self::provider('cachedWidgets');
    }

    public static function createCachedPersister(): InMemoryDataPersister
    {
        return self::persister('cachedWidgets');
    }

    public static function createDeprecatedProvider(): InMemoryDataProvider
    {
        return self::provider('deprecatedWidgets');
    }

    public static function createDeprecatedPersister(): InMemoryDataPersister
    {
        return self::persister('deprecatedWidgets');
    }

    public static function createPlainProvider(): InMemoryDataProvider
    {
        return self::provider('plainWidgets');
    }

    public static function createPlainPersister(): InMemoryDataPersister
    {
        return self::persister('plainWidgets');
    }

    private static function persister(string $type): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            $type,
            self::provider($type)->store(),
            static fn(): HeaderWidget => new HeaderWidget(),
        );
    }

    private static function provider(string $type): InMemoryDataProvider
    {
        return self::$providers[$type] ??= new InMemoryDataProvider(
            $type,
            ['1' => new HeaderWidget(1, 'seeded')],
            static function (object $item): string {
                \assert($item instanceof HeaderWidget);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof HeaderWidget);

                $item->id = (int) $id;
            },
        );
    }
}
