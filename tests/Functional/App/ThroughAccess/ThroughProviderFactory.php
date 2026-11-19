<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * One in-memory provider per type over its slice of {@see ThroughFixtures::graph()},
 * built once so the relations point at the objects the other providers serve.
 */
final class ThroughProviderFactory
{
    /**
     * @var array<string, list<object>>|null
     */
    private static ?array $graph = null;

    /**
     * @return list<object>
     */
    public static function slice(string $type): array
    {
        self::$graph ??= ThroughFixtures::graph();

        return self::$graph[$type] ?? [];
    }

    public static function provider(string $type): InMemoryDataProvider
    {
        $items = [];
        foreach (self::slice($type) as $item) {
            $items[self::idOf($item)] = $item;
        }

        return new InMemoryDataProvider($type, $items, static fn(object $item): string => self::idOf($item));
    }

    public static function idOf(object $item): string
    {
        $id = \property_exists($item, 'id') ? $item->id : null;

        return \is_scalar($id) ? (string) $id : '';
    }
}
