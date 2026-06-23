<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory `leaflets` / `stickers` graph for the
 * strict-sparse-fieldset-member conformance suite: two providers over one seeded
 * object graph, so a leaflet fetch returns the related sticker (and `?include=sticker`
 * expands it). The providers are built once and shared via the static graph so the
 * leaflet's `sticker` property points at the same {@see Sticker} the stickers store
 * serves; {@see reset()} drops them so each test boots a fresh graph.
 *
 * Seed: leaflet 1 ("Spring", secret "topsecret", internalRef "REF-1") linking sticker
 * 1 ("Star"); stickers 1-2 ("Star"/"Moon").
 */
final class StrictFieldsetFactory
{
    private static ?InMemoryDataProvider $leaflets = null;

    private static ?InMemoryDataProvider $stickers = null;

    public static function createLeaflets(): InMemoryDataProvider
    {
        return self::leaflets();
    }

    public static function createStickers(): InMemoryDataProvider
    {
        return self::stickers();
    }

    public static function createLeafletsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('leaflets', self::leaflets()->store(), static fn(): Leaflet => new Leaflet());
    }

    public static function createStickersPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('stickers', self::stickers()->store(), static fn(): Sticker => new Sticker());
    }

    /**
     * Resets the per-kernel singletons so each test boots a fresh graph.
     */
    public static function reset(): void
    {
        self::$leaflets = null;
        self::$stickers = null;
    }

    private static function leaflets(): InMemoryDataProvider
    {
        return self::$leaflets ??= self::buildLeaflets();
    }

    private static function stickers(): InMemoryDataProvider
    {
        self::$stickers ??= self::buildStickers();

        return self::$stickers;
    }

    private static function buildLeaflets(): InMemoryDataProvider
    {
        $stickers = self::stickers();
        $star = $stickers->store()->find('1');
        \assert($star instanceof Sticker);

        $leaflets = ['1' => new Leaflet(1, 'Spring', 'topsecret', 'REF-1', $star)];

        return new InMemoryDataProvider(
            'leaflets',
            $leaflets,
            static function (object $item): string {
                \assert($item instanceof Leaflet);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Leaflet);

                $item->id = (int) $id;
            },
        );
    }

    private static function buildStickers(): InMemoryDataProvider
    {
        $stickers = [
            '1' => new Sticker(1, 'Star'),
            '2' => new Sticker(2, 'Moon'),
        ];

        return new InMemoryDataProvider(
            'stickers',
            $stickers,
            static function (object $item): string {
                \assert($item instanceof Sticker);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Sticker);

                $item->id = (int) $id;
            },
        );
    }
}
