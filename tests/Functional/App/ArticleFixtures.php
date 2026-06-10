<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The one source of the `articles` (plus related `authors` / `comments`) seed
 * data: the in-memory provider and the Doctrine test database are seeded from
 * the same maps, so both functional suites assert against identical content.
 *
 * Titles are distinct and start with distinct uppercase ASCII, so byte-order
 * sorting is unambiguous; `category` deliberately carries ties (guide × 3,
 * news × 2) so multi-field sort composition is observable.
 *
 * Each article links to exactly one author (via {@see relationships()}) and to a
 * couple of comments, so the relationship-read conformance suite can assert the
 * to-one and to-many linkage on both providers.
 */
final class ArticleFixtures
{
    /**
     * Keyed by article id. PHP coerces the numeric-string keys to `int`, so
     * consumers cast back to `string` at the use site.
     *
     * @return array<int|string, array{title: string, body: string, category: string}>
     */
    public static function data(): array
    {
        return [
            '1' => ['title' => 'JSON:API in PHP', 'body' => 'A worked example.', 'category' => 'guide'],
            '2' => ['title' => 'Second article', 'body' => 'Another one.', 'category' => 'guide'],
            '3' => ['title' => 'Building bundles', 'body' => 'Symfony integration.', 'category' => 'news'],
            '4' => ['title' => 'Zebra patterns', 'body' => 'Stripes, mostly.', 'category' => 'guide'],
            '5' => ['title' => 'Async pipelines', 'body' => 'Queues and workers.', 'category' => 'news'],
        ];
    }

    /**
     * The `authors` seed, keyed by author id.
     *
     * @return array<string, array{name: string}>
     */
    public static function authors(): array
    {
        return [
            'a1' => ['name' => 'Ada Lovelace'],
            'a2' => ['name' => 'Grace Hopper'],
        ];
    }

    /**
     * The `comments` seed, keyed by comment id.
     *
     * @return array<string, array{body: string}>
     */
    public static function comments(): array
    {
        return [
            'c1' => ['body' => 'First!'],
            'c2' => ['body' => 'Nice write-up.'],
            'c3' => ['body' => 'Could use more detail.'],
            'c4' => ['body' => 'Bookmarked.'],
            'c5' => ['body' => 'Thanks for sharing.'],
        ];
    }

    /**
     * The relationship linkage per article id: the single author id and the list
     * of comment ids each article owns.
     *
     * @return array<int|string, array{author: string, comments: list<string>}>
     */
    public static function relationships(): array
    {
        return [
            '1' => ['author' => 'a1', 'comments' => ['c1', 'c2']],
            '2' => ['author' => 'a2', 'comments' => ['c3']],
            '3' => ['author' => 'a1', 'comments' => ['c4', 'c5']],
            '4' => ['author' => 'a2', 'comments' => []],
            '5' => ['author' => 'a1', 'comments' => []],
        ];
    }

    /**
     * The second, independent to-many linkage per article id: the comment ids
     * each article *features*, backing the load-aware `lazyComments` relation. A
     * separate association from {@see relationships()}'s `comments`, so on
     * Doctrine the `featuredComments` collection stays an uninitialised
     * PersistentCollection through a plain fetch (the omission case) while
     * `?include=lazyComments` initialises and emits it (include-wins).
     *
     * @return array<int|string, list<string>>
     */
    public static function featuredComments(): array
    {
        return [
            '1' => ['c1', 'c2'],
            '2' => [],
            '3' => [],
            '4' => [],
            '5' => [],
        ];
    }
}
