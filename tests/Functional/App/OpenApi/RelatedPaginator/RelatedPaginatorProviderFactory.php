<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\RelatedPaginator;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

final class RelatedPaginatorProviderFactory
{
    public static function shelves(): InMemoryDataProvider
    {
        $shelf = new Shelf('1', 'Fiction', self::bookList());

        return new InMemoryDataProvider('shelves', [$shelf->id => $shelf], static function (object $item): string {
            \assert($item instanceof Shelf);

            return $item->id;
        });
    }

    public static function books(): InMemoryDataProvider
    {
        $books = [];
        foreach (self::bookList() as $book) {
            $books[$book->id] = $book;
        }

        return new InMemoryDataProvider('books', $books, static function (object $item): string {
            \assert($item instanceof Book);

            return $item->id;
        });
    }

    /**
     * @return list<Book>
     */
    private static function bookList(): array
    {
        return [new Book('1', 'Dune'), new Book('2', 'Emma'), new Book('3', 'Ulysses')];
    }
}
