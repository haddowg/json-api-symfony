<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Builds the writable in-memory `books` / `authors` / `publishers` graph for the
 * flattened-attribute (`on()`) conformance suite (bundle ADR 0085): three providers
 * over ONE seeded object graph, so a book holds the SAME author/publisher object
 * instances the authors/publishers stores hold — the reference-sharing the
 * write-existing witness relies on (a flattened `authorName` write mutates the
 * author in place, and a re-fetch of `/authors/{id}` sees it).
 *
 * Seed (the SAME ids the Doctrine kernel's insertion order assigns):
 *
 *  - authors 1 "Ada Lovelace", 2 "Grace Hopper", 3 "Edsger Dijkstra";
 *  - publisher 1 "Acme Press" — SHARED by every book (a sibling registered type,
 *    populated but no longer flattened or eager-pinned);
 *  - books 1 "Algorithms" (author 1), 2 "Compilers" (author 2), 3 "Structured
 *    Programming" (author 3) — each with a DISTINCT author so the flattened read is
 *    the per-row N+1 the budget witness collapses, all sharing publisher 1; plus
 *    book 4 "Orphan" with NO author (the require-exists 422 write witness).
 *
 * The providers are built once and shared via the static graph so the persister's
 * resolver reads the same stores the read providers serve; {@see reset()} drops
 * them so each test boots a fresh, unmutated graph.
 */
final class FlattenProviderFactory
{
    private static ?InMemoryDataProvider $books = null;

    private static ?InMemoryDataProvider $authors = null;

    private static ?InMemoryDataProvider $publishers = null;

    private static ?InMemoryDataProvider $countries = null;

    public static function createBooks(): InMemoryDataProvider
    {
        return self::books();
    }

    public static function createAuthors(): InMemoryDataProvider
    {
        return self::authors();
    }

    public static function createPublishers(): InMemoryDataProvider
    {
        return self::publishers();
    }

    public static function createCountries(): InMemoryDataProvider
    {
        return self::countries();
    }

    public static function createBooksPersister(): InMemoryDataPersister
    {
        $authors = self::authors();
        $publishers = self::publishers();

        return new InMemoryDataPersister(
            'books',
            self::books()->store(),
            static fn(): FlattenBook => new FlattenBook(),
            static function (string $type, string $id) use ($authors, $publishers): ?object {
                return match ($type) {
                    'authors' => $authors->store()->find($id),
                    'publishers' => $publishers->store()->find($id),
                    default => null,
                };
            },
        );
    }

    /**
     * Resets the per-kernel singletons so each test boots a fresh, unmutated graph
     * (a flattened write in one test must not bleed into the next).
     */
    public static function reset(): void
    {
        self::$books = null;
        self::$authors = null;
        self::$publishers = null;
        self::$countries = null;
    }

    private static function books(): InMemoryDataProvider
    {
        return self::$books ??= self::buildBooks();
    }

    private static function authors(): InMemoryDataProvider
    {
        self::$authors ??= self::buildAuthors();

        return self::$authors;
    }

    private static function publishers(): InMemoryDataProvider
    {
        self::$publishers ??= self::buildPublishers();

        return self::$publishers;
    }

    private static function countries(): InMemoryDataProvider
    {
        self::$countries ??= self::buildCountries();

        return self::$countries;
    }

    private static function buildBooks(): InMemoryDataProvider
    {
        $authors = self::authors();
        $publisher = self::publishers()->store()->find('1');
        \assert($publisher instanceof FlattenPublisher);

        $ada = $authors->store()->find('1');
        $grace = $authors->store()->find('2');
        $edsger = $authors->store()->find('3');
        \assert($ada instanceof FlattenAuthor && $grace instanceof FlattenAuthor && $edsger instanceof FlattenAuthor);

        // Each book's `editor` (the VISIBLE backing relation for the flattened
        // `editorName`) starts as the SAME author as its `author`, so a same-body
        // update that SWITCHES `editor` to a different author proves the flattened
        // value lands on the NEW editor (associated in that body) and the previously
        // associated editor is left untouched.
        $books = [
            '1' => new FlattenBook(1, 'Algorithms', $ada, $publisher, $ada),
            '2' => new FlattenBook(2, 'Compilers', $grace, $publisher, $grace),
            '3' => new FlattenBook(3, 'Structured Programming', $edsger, $publisher, $edsger),
            // Book 4 has NO author: a flattened `authorName` read renders null, and a
            // flattened `authorName` WRITE is the require-exists 422 witness (a null
            // related model never auto-instantiates).
            '4' => new FlattenBook(4, 'Orphan', null, $publisher, null),
        ];

        return new InMemoryDataProvider('books', $books, self::identify(), self::assignId());
    }

    private static function buildAuthors(): InMemoryDataProvider
    {
        // Every author shares ONE country (the SECOND hop the multi-hop
        // `on('author.country')` walks to), so the multi-hop eager walk batches three
        // authors to ONE country round-trip — reference-shared with the countries
        // store so a re-fetch reads the same object the nested load wrote onto.
        $country = self::countries()->store()->find('1');
        \assert($country instanceof FlattenCountry);

        $authors = [
            '1' => new FlattenAuthor(1, 'Ada Lovelace', $country),
            '2' => new FlattenAuthor(2, 'Grace Hopper', $country),
            '3' => new FlattenAuthor(3, 'Edsger Dijkstra', $country),
        ];

        return new InMemoryDataProvider('authors', $authors, self::identify(), self::assignId());
    }

    private static function buildPublishers(): InMemoryDataProvider
    {
        $publishers = [
            '1' => new FlattenPublisher(1, 'Acme Press'),
        ];

        return new InMemoryDataProvider('publishers', $publishers, self::identify(), self::assignId());
    }

    private static function buildCountries(): InMemoryDataProvider
    {
        $countries = [
            '1' => new FlattenCountry(1, 'Wonderland'),
        ];

        return new InMemoryDataProvider('countries', $countries, self::identify(), self::assignId());
    }

    /**
     * @return \Closure(object): string
     */
    private static function identify(): \Closure
    {
        return static function (object $item): string {
            \assert(\property_exists($item, 'id'));

            return $item->id === null ? '' : (string) $item->id;
        };
    }

    /**
     * @return \Closure(object, string): void
     */
    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            \assert(\property_exists($item, 'id'));
            $item->id = (int) $id;
        };
    }
}
