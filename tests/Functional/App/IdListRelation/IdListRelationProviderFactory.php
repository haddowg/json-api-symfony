<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdListRelation;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * Shelf 1 stores its to-many `books` as ids, shelf 2 its to-one `featured`, and
 * shelf 3 stores objects throughout, as the in-memory store requires.
 */
final class IdListRelationProviderFactory
{
    public static function shelves(): InMemoryDataProvider
    {
        $dune = new Book('1', 'Dune');
        $shelves = [
            '1' => new Shelf('1', bookIds: ['1']),
            '2' => new Shelf('2', featuredId: '1'),
            '3' => new Shelf('3', bookIds: [$dune], featuredId: $dune),
        ];

        return new InMemoryDataProvider('shelves', $shelves, static function (object $item): string {
            \assert($item instanceof Shelf);

            return $item->id;
        });
    }

    public static function books(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('books', ['1' => new Book('1', 'Dune')], static function (object $item): string {
            \assert($item instanceof Book);

            return $item->id;
        });
    }
}
