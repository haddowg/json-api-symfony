<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\WindowedSeedData;

/**
 * Seeds the in-memory providers for the windowed-include-batch conformance witness
 * (bundle ADR 0065) with the SAME large graph the Doctrine kernel seeds
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\SeedsLargeWindowedRelations}), so the
 * in-memory and Doctrine (on/off) documents are asserted identical:
 *
 *  - article 1: 50 comments (ids 1..50, bodies `comment-00` .. `comment-49`) + both
 *    editors (Ada, Grace);
 *  - article 2: 3 comments (ids 51..53) + the Ada editor;
 *  - article 3: 2 comments TIED on body `tie` (ids 54, 55), PKs ascending.
 *
 * The ids match the Doctrine AUTO assignment (insertion order), so the rendered linkage
 * ids coincide.
 */
final class WindowedProviderFactory
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

    public static function createArticlesPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('articles', $provider->store(), static fn(): Article => new Article());
    }

    public static function createAuthorsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('authors', $provider->store(), static fn(): Author => new Author());
    }

    public static function createCommentsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('comments', $provider->store(), static fn(): Comment => new Comment());
    }

    /**
     * @return array{
     *     articles: array<int|string, Article>,
     *     authors: array<int|string, Author>,
     *     comments: array<int|string, Comment>,
     * }
     */
    private static function graph(): array
    {
        $ada = new Author(1, 'Ada Lovelace');
        $grace = new Author(2, 'Grace Hopper');
        $authors = ['1' => $ada, '2' => $grace];

        $comments = [];
        $id = 1;

        $largeComments = [];
        foreach (WindowedSeedData::largeBodies() as $body) {
            $comment = new Comment($id, $body);
            $comments[(string) $id] = $comment;
            $largeComments[] = $comment;
            $id++;
        }

        $smallComments = [];
        foreach (WindowedSeedData::smallBodies() as $body) {
            $comment = new Comment($id, $body);
            $comments[(string) $id] = $comment;
            $smallComments[] = $comment;
            $id++;
        }

        $tieComments = [];
        foreach (WindowedSeedData::tieBodies() as $body) {
            $comment = new Comment($id, $body);
            $comments[(string) $id] = $comment;
            $tieComments[] = $comment;
            $id++;
        }

        // Article 1's featuredComments AND pinnedComments mirror its comments (the same
        // 50-member set backs the non-countable `lazyComments` and the countable
        // `pinnedComments` relations), so both inverse-FK windowed-include assertions have
        // data to window.
        $articles = [
            '1' => new Article(1, 'Article one', '', '', null, null, null, null, $largeComments, $largeComments, null, editors: [$ada, $grace], pinnedComments: $largeComments),
            '2' => new Article(2, 'Article two', '', '', null, null, null, null, $smallComments, [], null, editors: [$ada], pinnedComments: $smallComments),
            '3' => new Article(3, 'Article three', '', '', null, null, null, null, $tieComments, [], null, editors: [], pinnedComments: $tieComments),
        ];

        return ['articles' => $articles, 'authors' => $authors, 'comments' => $comments];
    }
}
