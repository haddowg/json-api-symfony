<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Query\InMemoryRelationCountFilterArm;
use haddowg\JsonApiBundle\Tests\Functional\App\Query\InMemoryRelationCountSortArm;

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
     * The `articles` provider wired with the relation-count demonstrator arms, so a
     * custom {@see \haddowg\JsonApiBundle\Tests\Functional\App\Query\RelationCountAtLeast}
     * filter and {@see \haddowg\JsonApiBundle\Tests\Functional\App\Query\OrderByRelationCount}
     * sort execute in memory (the conformance witness for the Doctrine push-down). The
     * in-memory provider is hand-constructed, so the arms are passed here rather than
     * autoconfigured as services (the Doctrine half tags them).
     */
    public static function createArticlesWithCountArms(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'articles',
            self::graph()['articles'],
            filterArms: [new InMemoryRelationCountFilterArm()],
            sortArms: [new InMemoryRelationCountSortArm()],
        );
    }

    /**
     * Persisters sharing each provider's store, so the write-capable `articles` /
     * `authors` / `comments` resources are servable (the warm-up guard requires a
     * persister per write-exposing type). Each shares the store of the provider it
     * is handed, mirroring how the Doctrine pair shares one EntityManager.
     */
    public static function articlesPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('articles', $provider->store(), static fn(): Article => new Article());
    }

    public static function authorsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('authors', $provider->store(), static fn(): Author => new Author());
    }

    public static function commentsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('comments', $provider->store(), static fn(): Comment => new Comment());
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
            $authors[(string) $id] = new Author((int) $id, $author['name']);
        }

        $comments = [];
        foreach (ArticleFixtures::comments() as $id => $comment) {
            $comments[(string) $id] = new Comment((int) $id, $comment['body']);
        }

        $relationships = ArticleFixtures::relationships();
        $featured = ArticleFixtures::featuredComments();
        $editors = ArticleFixtures::editors();

        $articles = [];
        foreach (ArticleFixtures::data() as $id => $article) {
            $id = (string) $id;
            $links = $relationships[$id] ?? ['author' => null, 'comments' => []];

            $author = $links['author'] !== null ? ($authors[$links['author']] ?? null) : null;
            $articleComments = self::pick($comments, $links['comments']);
            $featuredComments = self::pick($comments, $featured[$id] ?? []);
            $articleEditors = self::pickAuthors($authors, $editors[$id] ?? []);

            $articleModel = new Article(
                (int) $id,
                $article['title'],
                $article['body'],
                $article['category'],
                null,
                null,
                null,
                $author,
                $articleComments,
                $featuredComments,
                editors: $articleEditors,
            );

            // Wire each owned comment's `article` back-reference to its owning
            // article — the in-memory twin of the Doctrine CommentEntity's
            // owning-side ManyToOne — so the multi-hop traversal filter
            // `comments.article.title` chains the same way on both providers.
            foreach ($articleComments as $comment) {
                $comment->article = $articleModel;
            }

            $articles[$id] = $articleModel;
        }

        return ['articles' => $articles, 'authors' => $authors, 'comments' => $comments];
    }

    /**
     * The comment objects for the given ids, in order, skipping any unknown id.
     *
     * @param array<string, Comment> $comments
     * @param list<string>           $ids
     *
     * @return list<Comment>
     */
    private static function pick(array $comments, array $ids): array
    {
        $picked = [];
        foreach ($ids as $commentId) {
            if (isset($comments[$commentId])) {
                $picked[] = $comments[$commentId];
            }
        }

        return $picked;
    }

    /**
     * The author objects for the given ids, in order, skipping any unknown id —
     * the in-memory backing for the many-to-many `editors` relation.
     *
     * @param array<string, Author> $authors
     * @param list<string>          $ids
     *
     * @return list<Author>
     */
    private static function pickAuthors(array $authors, array $ids): array
    {
        $picked = [];
        foreach ($ids as $authorId) {
            if (isset($authors[$authorId])) {
                $picked[] = $authors[$authorId];
            }
        }

        return $picked;
    }
}
