<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Two shelves of books whose id, title and year orders all differ. Shelf 1 holds
 * Bravo 2001, Delta 1999, Alpha 2005 and Charlie 1990 as books 1 to 4, and picks books
 * 1, 3 and 4 at ranks 3, 1 and 2. Shelf 2 holds Echo 2010 and Foxtrot 2020. Shelf 3 is
 * empty.
 */
final class OrderFixtures
{
    public const array SHELVES = [1 => 'Fiction', 2 => 'Poetry', 3 => 'Empty'];

    /**
     * Each book's title, year, shelf and position on it.
     */
    public const array BOOKS = [
        1 => ['Bravo', 2001, 1, 0],
        2 => ['Delta', 1999, 1, 2],
        3 => ['Alpha', 2005, 1, 1],
        4 => ['Charlie', 1990, 1, 3],
        5 => ['Echo', 2010, 2, 0],
        6 => ['Foxtrot', 2020, 2, 1],
    ];

    /**
     * Each pick's shelf, book and rank, in insertion order.
     */
    public const array PICKS = [
        [1, 1, 3],
        [1, 3, 1],
        [1, 4, 2],
    ];

    public const array MAGAZINES = [1 => 'Monthly', 2 => 'Weekly'];

    /**
     * Each shelf item's shelf, type, id, rank and note, in insertion order.
     */
    public const array ITEMS = [
        [1, 'books', 1, 3, 'c'],
        [1, 'magazines', 1, 1, 'a'],
        [1, 'books', 2, 2, 'b'],
        [1, 'magazines', 2, 4, 'd'],
    ];

    /**
     * @var array<string, InMemoryDataProvider>|null
     */
    private ?array $providers = null;

    /**
     * The object graph both kernels seed.
     *
     * @return array{shelves: list<OrderShelf>, books: list<OrderBook>, picks: list<OrderPick>, magazines: list<OrderMagazine>}
     */
    public static function graph(): array
    {
        $shelves = [];
        foreach (self::SHELVES as $id => $name) {
            $shelves[$id] = new OrderShelf($id, $name);
        }

        $books = [];
        foreach (self::BOOKS as $id => [$title, $year, $shelf, $position]) {
            $book = new OrderBook($id, $title, $year, $shelves[$shelf], $position);
            $shelves[$shelf]->books->add($book);
            $books[$id] = $book;
        }

        $picks = [];
        foreach (self::PICKS as $position => [$shelf, $book, $rank]) {
            $pick = new OrderPick(null, $shelves[$shelf], $books[$book] ?? throw new \LogicException('No book ' . $book), $rank, $position);
            $shelves[$shelf]->picks->add($pick);
            $picks[] = $pick;
        }

        $magazines = [];
        foreach (self::MAGAZINES as $id => $title) {
            $magazines[] = new OrderMagazine($id, $title);
        }

        foreach (self::ITEMS as $index => [$shelf, $type, $id, $rank, $note]) {
            $shelves[$shelf]->items->add(new OrderShelfItem($index + 1, $shelves[$shelf], $type, $id, $rank, $note, $index));
        }

        return ['shelves' => \array_values($shelves), 'books' => \array_values($books), 'picks' => $picks, 'magazines' => $magazines];
    }

    public function provider(string $type): InMemoryDataProvider
    {
        if ($this->providers === null) {
            $graph = self::graph();
            $identify = static fn(object $item): string => $item instanceof OrderShelf || $item instanceof OrderBook || $item instanceof OrderMagazine ? (string) $item->id : '';
            $this->providers = [];
            foreach (['shelves', 'books', 'magazines'] as $name) {
                $byId = [];
                foreach ($graph[$name] as $item) {
                    $byId[(string) $item->id] = $item;
                }
                $this->providers[$name] = new InMemoryDataProvider($name, $byId, $identify);
            }
        }

        return $this->providers[$type];
    }

    public function persister(string $type): InMemoryDataPersister
    {
        return new InMemoryDataPersister(
            $type,
            $this->provider($type)->store(),
            static fn(): object => match ($type) {
                'shelves' => new OrderShelf(),
                'magazines' => new OrderMagazine(),
                default => new OrderBook(),
            },
            fn(string $relatedType, string $id): ?object => $this->provider($relatedType)->store()->find($id),
        );
    }
}
