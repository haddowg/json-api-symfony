<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory stores for the lifecycle-hooks kernel: a writable provider +
 * persister for each widget type (`hookWidgets` event-path, `hookableWidgets`
 * method-path) sharing per-type stores, plus a read-only `hookOwners` provider the
 * widget persisters' related-resolver reads so a relationship mutation can set the
 * `owner` association. The per-kernel singletons keep each provider/persister pair
 * pointed at one store, so a write is immediately readable.
 */
final class HookWidgetFactory
{
    private static ?InMemoryDataProvider $hookWidgets = null;

    private static ?InMemoryDataProvider $hookableWidgets = null;

    private static ?InMemoryDataProvider $owners = null;

    public static function reset(): void
    {
        self::$hookWidgets = null;
        self::$hookableWidgets = null;
        self::$owners = null;
    }

    public static function createHookWidgets(): InMemoryDataProvider
    {
        return self::hookWidgets();
    }

    public static function createHookableWidgets(): InMemoryDataProvider
    {
        return self::hookableWidgets();
    }

    public static function createOwners(): InMemoryDataProvider
    {
        return self::owners();
    }

    public static function createHookWidgetsPersister(): InMemoryDataPersister
    {
        return self::persister('hookWidgets', self::hookWidgets());
    }

    public static function createHookableWidgetsPersister(): InMemoryDataPersister
    {
        return self::persister('hookableWidgets', self::hookableWidgets());
    }

    private static function persister(string $type, InMemoryDataProvider $provider): InMemoryDataPersister
    {
        $owners = self::owners();

        return new InMemoryDataPersister(
            $type,
            $provider->store(),
            static fn(): HookWidget => new HookWidget(),
            static fn(string $relatedType, string $id): ?object => $relatedType === 'hookOwners'
                ? $owners->store()->find($id)
                : null,
        );
    }

    private static function hookWidgets(): InMemoryDataProvider
    {
        return self::$hookWidgets ??= self::buildWidgets('hookWidgets');
    }

    private static function hookableWidgets(): InMemoryDataProvider
    {
        return self::$hookableWidgets ??= self::buildWidgets('hookableWidgets');
    }

    private static function owners(): InMemoryDataProvider
    {
        return self::$owners ??= new InMemoryDataProvider(
            'hookOwners',
            ['1' => new HookOwner(1, 'Ada'), '2' => new HookOwner(2, 'Grace')],
            static function (object $item): string {
                \assert($item instanceof HookOwner);

                return $item->id === null ? '' : (string) $item->id;
            },
        );
    }

    private static function buildWidgets(string $type): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            $type,
            ['1' => new HookWidget(1, 'first', '', new HookOwner(1, 'Ada'))],
            static function (object $item): string {
                \assert($item instanceof HookWidget);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof HookWidget);

                $item->id = (int) $id;
            },
        );
    }
}
