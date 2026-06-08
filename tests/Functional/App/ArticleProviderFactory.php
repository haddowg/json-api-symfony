<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * A factory that seeds an {@see InMemoryDataProvider} with the `articles`
 * fixtures. The seed objects cannot be passed as service-configuration argument
 * literals, so the kernel registers this factory's static method instead.
 */
final class ArticleProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        $articles = [];
        foreach (ArticleFixtures::data() as $id => $article) {
            $articles[(string) $id] = new Article((string) $id, $article['title'], $article['body'], $article['category']);
        }

        return new InMemoryDataProvider('articles', $articles);
    }
}
