<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * A factory that seeds the in-memory providers for `articles` and its related
 * `authors` / `comments` from the shared {@see ArticleFixtures}. The seed
 * objects cannot be passed as service-configuration argument literals, so the
 * kernel registers these static methods instead.
 *
 * The full object graph is built once: each seeded {@see Article} holds the
 * actual {@see Author} object and a list of the actual {@see Comment} objects it
 * links to, so core's accessor reads related **objects** (not ids) when building
 * relationship linkage on a read.
 */
final class ArticleProviderFactory
{
    public static function createArticles(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('articles', self::graph()['articles']);
    }

    public static function createAuthors(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('authors', self::graph()['authors']);
    }

    public static function createComments(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('comments', self::graph()['comments']);
    }

    /**
     * Builds the seeded object graph: authors and comments keyed by id, and the
     * articles wired to hold their related objects.
     *
     * @return array{
     *     articles: array<string, Article>,
     *     authors: array<string, Author>,
     *     comments: array<string, Comment>,
     * }
     */
    private static function graph(): array
    {
        $authors = [];
        foreach (ArticleFixtures::authors() as $id => $author) {
            $authors[(string) $id] = new Author((string) $id, $author['name']);
        }

        $comments = [];
        foreach (ArticleFixtures::comments() as $id => $comment) {
            $comments[(string) $id] = new Comment((string) $id, $comment['body']);
        }

        $relationships = ArticleFixtures::relationships();

        $articles = [];
        foreach (ArticleFixtures::data() as $id => $article) {
            $id = (string) $id;
            $links = $relationships[$id] ?? ['author' => null, 'comments' => []];

            $author = $links['author'] !== null ? ($authors[$links['author']] ?? null) : null;
            $articleComments = [];
            foreach ($links['comments'] as $commentId) {
                if (isset($comments[$commentId])) {
                    $articleComments[] = $comments[$commentId];
                }
            }

            $articles[$id] = new Article(
                $id,
                $article['title'],
                $article['body'],
                $article['category'],
                null,
                null,
                null,
                $author,
                $articleComments,
            );
        }

        return ['articles' => $articles, 'authors' => $authors, 'comments' => $comments];
    }
}
