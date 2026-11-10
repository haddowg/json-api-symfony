<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * Orders a relation by a key the books type doesn't sort by, which fails warm-up.
 */
final class UnknownOrderShelfResource extends OrderShelfResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            HasMany::make('booksByPages', 'books')
                ->storedAs('books')
                ->defaultSort(new SortDirective(SortByField::make('pages'), descending: false)),
        ];
    }
}
