<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * `books` declares its own order, title descending, replacing the books' own oldest
 * first; `booksByYear` reads the same books and keeps the books' order.
 */
class OrderShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('books', 'books')
                ->withData()
                ->paginate(PagePaginator::make())
                ->defaultSort(new SortDirective(SortByField::make('title'), descending: true)),
            HasMany::make('booksByYear', 'books')
                ->storedAs('books')
                ->withData(),
        ];
    }
}
