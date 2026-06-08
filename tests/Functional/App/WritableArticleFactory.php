<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `articles` pair: an {@see InMemoryDataProvider}
 * seeded with the fixtures and an {@see InMemoryDataPersister} over the *same*
 * store (passed the provider so it shares {@see InMemoryDataProvider::store()}),
 * so a created/updated/deleted resource is immediately readable. The seed objects
 * cannot be service-configuration literals, so the kernel registers these
 * factory methods.
 */
final class WritableArticleFactory
{
    public static function createProvider(): InMemoryDataProvider
    {
        $articles = [];
        foreach (ArticleFixtures::data() as $id => $article) {
            $articles[(string) $id] = new Article((string) $id, $article['title'], $article['body'], $article['category']);
        }

        return new InMemoryDataProvider('articles', $articles, static function (object $item): string {
            \assert($item instanceof Article);

            return $item->id;
        });
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('articles', $provider->store(), static fn(): Article => new Article());
    }
}
