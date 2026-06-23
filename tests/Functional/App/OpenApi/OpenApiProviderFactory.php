<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the in-memory providers (and matching persisters) backing the OpenAPI
 * document witness — one per type, each seeded with a row so the registered
 * resources resolve and the served document describes a real surface. Both
 * `products` and `categories` expose the full CRUD set, so each is paired with a
 * persister sharing the provider's store — otherwise the servability warm-up guard
 * rightly fails the build for a write route with no persister.
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

    public static function productsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('products', $provider->store(), static fn(): Product => new Product());
    }

    public static function categoriesPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('categories', $provider->store(), static fn(): Category => new Category());
    }
}
