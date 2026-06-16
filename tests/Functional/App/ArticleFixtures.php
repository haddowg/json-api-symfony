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
     * The `authors` seed, keyed by author id. The ids are per-type sequential
     * ints (`1`, `2`) matching the order the Doctrine factory inserts them, since
     * a store-provided `AUTO` column ignores a pre-set id and assigns by insertion
     * order. PHP coerces the numeric-string keys to `int`.
     *
     * @return array<int|string, array{name: string}>
     */
    public static function authors(): array
    {
        return [
            '1' => ['name' => 'Ada Lovelace'],
            '2' => ['name' => 'Grace Hopper'],
        ];
    }

    /**
     * The `comments` seed, keyed by comment id. The ids are per-type sequential
     * ints (`1`-`5`) matching the order the Doctrine factory inserts them; JSON:API
     * ids are per-type, so comment `1` and article `1` coexist. PHP coerces the
     * numeric-string keys to `int`.
     *
     * @return array<int|string, array{body: string}>
     */
    public static function comments(): array
    {
        return [
            '1' => ['body' => 'First!'],
            '2' => ['body' => 'Nice write-up.'],
            '3' => ['body' => 'Could use more detail.'],
            '4' => ['body' => 'Bookmarked.'],
            '5' => ['body' => 'Thanks for sharing.'],
        ];
    }

    /**
     * The relationship linkage per article id: the single author id (`null` for an
     * authorless article) and the list of comment ids each article owns.
     *
     * Article 5 is deliberately authorless (`author => null`) so the
     * related/relationship-endpoint suites can witness a null to-one: it keeps the
     * collection at five rows (so the read-query count assertions are untouched)
     * while `GET /articles/5/author` renders `data:null` on both providers.
     *
     * @return array<int|string, array{author: ?string, comments: list<string>}>
     */
    public static function relationships(): array
    {
        return [
            '1' => ['author' => '1', 'comments' => ['1', '2']],
            '2' => ['author' => '2', 'comments' => ['3']],
            '3' => ['author' => '1', 'comments' => ['4', '5']],
            '4' => ['author' => '2', 'comments' => []],
            '5' => ['author' => null, 'comments' => []],
        ];
    }

    /**
     * The many-to-many `editors` linkage per article id: the editor author ids
     * each article has, distinct from the to-one `author` of
     * {@see relationships()}. This backs the unidirectional ManyToMany `editors`
     * relation (article → authors), so it reuses the `authors` type without a new
     * one. Author `1` is an editor of articles 1 and 2 — a shared,
     * cross-parent membership the Doctrine subquery scope must return for each
     * parent without bleed.
     *
     * @return array<int|string, list<string>>
     */
    public static function editors(): array
    {
        return [
            '1' => ['1', '2'],
            '2' => ['1'],
            '3' => ['2'],
            '4' => [],
            '5' => [],
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
            '1' => ['1', '2'],
            '2' => [],
            '3' => [],
            '4' => [],
            '5' => [],
        ];
    }
}
