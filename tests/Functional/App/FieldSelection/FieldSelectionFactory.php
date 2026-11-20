<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Wires the in-memory `panels` and `curators` providers over one seeded graph, so panel 1
 * links curator 1, and a persister for each sharing its store. {@see reset()} drops the
 * graph so each test boots a fresh one.
 */
final class FieldSelectionFactory
{
    public const array SEED_VALUES = ['cap' => '10', 'countries' => 'GB,FR', 'region' => 'EU'];
    public const array SEED_GROUPS = ['rows' => [['a' => 1], ['a' => 2]], 'extra' => [['a' => 3]]];
    public const array SEED_SETTINGS = ['theme' => 'dark'];
    public const array SEED_STATS = ['rating' => 5, 'reviews' => 12, 'secret' => 1];
    public const array SEED_ADDRESS = ['street' => '1 High Street', 'postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5, 'lng' => -0.1, 'accuracy' => 'rooftop'], 'history' => [['street' => '9 Low Road']]];
    public const array SEED_OFFICE = ['city' => 'Paris', 'geo' => ['lat' => 48.8, 'lng' => 2.3]];
    public const array SEED_ROWS = [
        ['label' => 'A', 'size' => ['w' => 1, 'h' => 2], 'cells' => [['v' => 'x'], ['v' => 'y']], 'note' => 'first'],
        ['label' => 'B', 'size' => ['w' => 3, 'h' => 4], 'cells' => []],
    ];
    public const array SEED_TAGS = ['red', 'blue'];
    public const array SEED_BLOCKS = [
        ['type' => 'text', 'title' => 'Intro', 'body' => 'Hello', 'meta' => ['lang' => 'en', 'tone' => 'warm']],
        ['type' => 'image', 'title' => 'Pic', 'url' => '/a.png'],
        ['type' => 'divider', 'style' => 'dashed'],
    ];
    public const array SEED_EXHIBITS = [['title' => 'Light', 'year' => 2001], ['title' => 'Sound', 'year' => 2004]];

    private static ?InMemoryDataProvider $panels = null;

    private static ?InMemoryDataProvider $curators = null;

    public static function createPanels(): InMemoryDataProvider
    {
        return self::$panels ??= self::buildPanels();
    }

    public static function createCurators(): InMemoryDataProvider
    {
        return self::$curators ??= self::buildCurators();
    }

    public static function createPanelsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('panels', self::createPanels()->store(), static fn(): Panel => new Panel());
    }

    /**
     * The seeded panel served as the `boards` type, rendered through a serializer override.
     */
    public static function createBoards(): InMemoryDataProvider
    {
        return self::panelsAs('boards');
    }

    /**
     * The seeded panel served as the `easels` type, rendered through a serializer override
     * that receives the selected keys.
     */
    public static function createEasels(): InMemoryDataProvider
    {
        return self::panelsAs('easels');
    }

    public static function createCuratorsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('curators', self::createCurators()->store(), static fn(): Curator => new Curator());
    }

    public static function reset(): void
    {
        self::$panels = null;
        self::$curators = null;
    }

    private static function buildPanels(): InMemoryDataProvider
    {
        $curator = self::createCurators()->store()->find('1');
        \assert($curator instanceof Curator);

        return new InMemoryDataProvider(
            'panels',
            ['1' => new Panel('1', 'Seed', self::SEED_VALUES, self::SEED_GROUPS, self::SEED_SETTINGS, $curator, self::SEED_ADDRESS, self::SEED_ROWS, [], self::SEED_TAGS, self::SEED_BLOCKS)],
            static function (object $item): string {
                \assert($item instanceof Panel);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Panel);

                $item->id = $id;
            },
        );
    }

    private static function panelsAs(string $type): InMemoryDataProvider
    {
        $panel = self::createPanels()->store()->find('1');
        \assert($panel instanceof Panel);

        return new InMemoryDataProvider(
            $type,
            ['1' => $panel],
            static function (object $item): string {
                \assert($item instanceof Panel);

                return $item->id;
            },
        );
    }

    private static function buildCurators(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'curators',
            ['1' => new Curator('1', 'Ada', self::SEED_STATS, self::SEED_OFFICE, self::SEED_EXHIBITS)],
            static function (object $item): string {
                \assert($item instanceof Curator);

                return $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Curator);

                $item->id = $id;
            },
        );
    }
}
