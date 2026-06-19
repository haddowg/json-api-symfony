<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\MultiServer;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory providers backing the multi-server OpenAPI witness — one per
 * type. The SPI resolves by type, so each server's resource reads from its own
 * provider.
 */
final class MultiServerItemFactory
{
    public static function publicItems(): InMemoryDataProvider
    {
        return self::provider('public-items', new Item('1', 'Public item'));
    }

    public static function adminItems(): InMemoryDataProvider
    {
        return self::provider('admin-items', new Item('1', 'Admin item'));
    }

    private static function provider(string $type, Item $item): InMemoryDataProvider
    {
        return new InMemoryDataProvider($type, [$item->id => $item], static function (object $value): string {
            \assert($value instanceof Item);

            return $value->id;
        });
    }
}
