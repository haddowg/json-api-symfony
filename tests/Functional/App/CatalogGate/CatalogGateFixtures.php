<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Hub 1, owned by ada, holds spoke 1, rim 1 and leads with spoke 1. Owned hub 1 is
 * ada's too.
 */
final class CatalogGateFixtures
{
    public static function provider(string $type): InMemoryDataProvider
    {
        $spoke = new Spoke(1, 'front');
        $rim = new Rim(1, 'outer');
        $items = match ($type) {
            'hubs', 'ownedHubs' => ['1' => new Hub(1, 'ada', [$spoke], [$rim], $spoke)],
            'spokes' => ['1' => $spoke],
            default => ['1' => $rim],
        };

        return new InMemoryDataProvider($type, $items, static fn(object $item): string => \property_exists($item, 'id') && \is_int($item->id) ? (string) $item->id : '');
    }
}
