<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `articles` / `authors` / `comments` graph for the
 * relationship-mutation conformance suite: three providers over one seeded object
 * graph (so a parent fetch returns the related objects), each writable, plus an
 * {@see InMemoryDataPersister} for `articles` whose related-object resolver looks
 * an author/comment id up in the right provider's store — so a relationship
 * mutation can resolve a linkage id back to the stored object and set it on the
 * parent's association.
 *
 * The providers are built once and shared via the static graph so the persister's
 * resolver reads the same stores the read providers serve.
 */
final class RelationshipMutationFactory
{
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
        $authors = self::authors();
        $comments = self::comments();

        return new InMemoryDataPersister(
            'articles',
            self::articles()->store(),
            static fn(): Article => new Article(),
            static function (string $type, string $id) use ($authors, $comments): ?object {
                return match ($type) {
                    'authors' => $authors->store()->find($id),
                    'comments' => $comments->store()->find($id),
                    default => null,
                };
            },
        );
    }

    public static function createAuthorsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('authors', self::authors()->store(), static fn(): Author => new Author());
    }

    public static function createCommentsPersister(): InMemoryDataPersister
    {
        return new InMemoryDataPersister('comments', self::comments()->store(), static fn(): Comment => new Comment());
    }

    /**
     * Resets the per-kernel singletons so each test boots a fresh, unmutated
     * object graph.
     */
    public static function reset(): void
    {
        self::$articles = null;
        self::$authors = null;
        self::$comments = null;
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
        $featured = ArticleFixtures::featuredComments();

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
                self::pick($comments, $featured[$id] ?? []),
            );
        }

        return new InMemoryDataProvider(
            'articles',
            $articles,
            static function (object $item): string {
                \assert($item instanceof Article);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Article);

                $item->id = (int) $id;
            },
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
            static function (object $item): string {
                \assert($item instanceof Author);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Author);

                $item->id = (int) $id;
            },
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
            static function (object $item): string {
                \assert($item instanceof Comment);

                return $item->id === null ? '' : (string) $item->id;
            },
            static function (object $item, string $id): void {
                \assert($item instanceof Comment);

                $item->id = (int) $id;
            },
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
