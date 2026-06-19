<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory providers backing the OpenAPI document witness — one per type,
 * each seeded with a row so the registered resources resolve and the served document
 * describes a real surface.
 */
final class OpenApiProviderFactory
{
    public static function products(): InMemoryDataProvider
    {
        $product = new Product('1', 'Widget', 'published', '1', ['1']);

        return new InMemoryDataProvider('products', [$product->id => $product], static function (object $item): string {
            \assert($item instanceof Product);

            return $item->id;
        });
    }

    public static function categories(): InMemoryDataProvider
    {
        $category = new Category('1', 'Tools');

        return new InMemoryDataProvider('categories', [$category->id => $category], static function (object $item): string {
            \assert($item instanceof Category);

            return $item->id;
        });
    }
}
