<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CountThrough;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * One in-memory provider per type, each over its slice of {@see CountThroughFixtures::graph()}
 * built once, so the relations point at the same objects the other providers serve.
 */
final class CountThroughProviderFactory
{
    /**
     * @var array<string, list<object>>|null
     */
    private static ?array $graph = null;

    public static function provider(string $type, string $slice): InMemoryDataProvider
    {
        self::$graph ??= CountThroughFixtures::graph();

        $items = [];
        foreach (self::$graph[$slice] ?? [] as $item) {
            $items[self::idOf($item)] = $item;
        }

        return new InMemoryDataProvider($type, $items, static fn(object $item): string => self::idOf($item));
    }

    private static function idOf(object $item): string
    {
        $id = \property_exists($item, 'id') ? $item->id : null;

        return \is_scalar($id) ? (string) $id : '';
    }
}
