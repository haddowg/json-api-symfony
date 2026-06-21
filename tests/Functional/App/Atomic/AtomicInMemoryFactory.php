<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Atomic;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Author;
use haddowg\JsonApiBundle\Tests\Functional\App\Comment;

/**
 * Builds the writable in-memory `articles` / `authors` / `comments` graph for the
 * Atomic Operations conformance suite — the same shape as
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationFactory},
 * but every store shares ONE {@see InMemorySnapshotCoordinator} so an atomic
 * rollback restores the whole cross-store object graph in a single serialize pass
 * (cross-store object identity intact).
 *
 * `authors` and `comments` are writable too (each over the same store its read
 * provider serves) so a batch can `add` a new comment and reference it by `lid` from
 * a later operation, and the persisters' related-object resolver reads the live
 * authors/comments stores so a relationship linkage resolves to the stored object.
 */
final class AtomicInMemoryFactory
{
    private static ?InMemorySnapshotCoordinator $coordinator = null;

    private static ?InMemoryDataProvider $articles = null;

    private static ?InMemoryDataProvider $authors = null;

    private static ?InMemoryDataProvider $comments = null;

    public static function createArticles(): InMemoryDataProvider
    {
        return self::articles();
    }

    public static function createAuthors(): InMemoryDataProvider
    {
        return self::authors();
    }

    public static function createComments(): InMemoryDataProvider
    {
        return self::comments();
    }

    public static function createArticlesPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'articles',
            self::articles()->store(),
            static fn(): Article => new Article(),
            self::resolver(),
        );
    }

    public static function createAuthorsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'authors',
            self::authors()->store(),
            static fn(): Author => new Author(),
            self::resolver(),
        );
    }

    public static function createCommentsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            'comments',
            self::comments()->store(),
            static fn(): Comment => new Comment(),
            self::resolver(),
        );
    }

    /**
     * Resets the per-kernel singletons so each test boots a fresh, unmutated object
     * graph (with a fresh coordinator).
     */
    public static function reset(): void
    {
        self::$coordinator = null;
        self::$articles = null;
        self::$authors = null;
        self::$comments = null;
    }

    private static function resolver(): \Closure
    {
        $authors = self::authors();
        $comments = self::comments();

        return static function (string $type, string $id) use ($authors, $comments): ?object {
            return match ($type) {
                'authors' => $authors->store()->find($id),
                'comments' => $comments->store()->find($id),
                default => null,
            };
        };
    }

    private static function coordinator(): InMemorySnapshotCoordinator
    {
        return self::$coordinator ??= new InMemorySnapshotCoordinator();
    }

    private static function articles(): InMemoryDataProvider
    {
        return self::$articles ??= self::buildArticles();
    }

    private static function authors(): InMemoryDataProvider
    {
        self::$authors ??= self::buildAuthors();

        return self::$authors;
    }

    private static function comments(): InMemoryDataProvider
    {
        self::$comments ??= self::buildComments();

        return self::$comments;
    }

    private static function buildArticles(): InMemoryDataProvider
    {
        $authors = self::authors();
        $comments = self::comments();

        $relationships = ArticleFixtures::relationships();

        $articles = [];
        foreach (ArticleFixtures::data() as $id => $article) {
            $id = (string) $id;
            $links = $relationships[$id] ?? ['author' => null, 'comments' => []];

            $author = $links['author'] !== null ? $authors->store()->find($links['author']) : null;
            \assert($author === null || $author instanceof Author);

            $articles[$id] = new Article(
                (int) $id,
                $article['title'],
                $article['body'],
                $article['category'],
                null,
                null,
                null,
                $author,
                self::pick($comments, $links['comments']),
            );
        }

        return new InMemoryDataProvider(
            'articles',
            $articles,
            static fn(object $item): string => $item instanceof Article && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Article);
                $item->id = (int) $id;
            },
            coordinator: self::coordinator(),
        );
    }

    private static function buildAuthors(): InMemoryDataProvider
    {
        $authors = [];
        foreach (ArticleFixtures::authors() as $id => $author) {
            $authors[(string) $id] = new Author((int) $id, $author['name']);
        }

        return new InMemoryDataProvider(
            'authors',
            $authors,
            static fn(object $item): string => $item instanceof Author && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Author);
                $item->id = (int) $id;
            },
            coordinator: self::coordinator(),
        );
    }

    private static function buildComments(): InMemoryDataProvider
    {
        $comments = [];
        foreach (ArticleFixtures::comments() as $id => $comment) {
            $comments[(string) $id] = new Comment((int) $id, $comment['body']);
        }

        return new InMemoryDataProvider(
            'comments',
            $comments,
            static fn(object $item): string => $item instanceof Comment && $item->id !== null ? (string) $item->id : '',
            static function (object $item, string $id): void {
                \assert($item instanceof Comment);
                $item->id = (int) $id;
            },
            coordinator: self::coordinator(),
        );
    }

    /**
     * The comment objects for the given ids, in order, skipping any unknown id.
     *
     * @param list<string> $ids
     *
     * @return list<Comment>
     */
    private static function pick(InMemoryDataProvider $comments, array $ids): array
    {
        $picked = [];
        foreach ($ids as $commentId) {
            $comment = $comments->store()->find($commentId);
            if ($comment instanceof Comment) {
                $picked[] = $comment;
            }
        }

        return $picked;
    }
}
