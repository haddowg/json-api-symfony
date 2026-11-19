<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * One provider per type, each over its slice of {@see ThroughFixtures::graph()} built
 * once, so the relations point at the same objects the other providers serve.
 */
final class ThroughProviderFactory
{
    /**
     * @var array<string, list<object>>|null
     */
    private static ?array $graph = null;

    public static function provider(string $type, string $slice): InMemoryDataProvider
    {
        $items = [];
        foreach (self::graph()[$slice] ?? [] as $item) {
            $items[self::idOf($item)] = $item;
        }

        return new InMemoryDataProvider($type, $items, static fn(object $item): string => self::idOf($item));
    }

    public static function advertisers(): ScopedThrAdvertiserProvider
    {
        /** @var list<Doctrine\ThrAdvertiserEntity> $advertisers */
        $advertisers = self::graph()['advertisers'];

        return new ScopedThrAdvertiserProvider($advertisers);
    }

    /**
     * @return array<string, list<object>>
     */
    private static function graph(): array
    {
        return self::$graph ??= ThroughFixtures::graph();
    }

    private static function idOf(object $item): string
    {
        $id = \property_exists($item, 'id') ? $item->id : null;

        return \is_scalar($id) ? (string) $id : '';
    }
}
