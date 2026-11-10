<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * The shelves with a pivot relation ordered by a pivot field, `picks` by rank. Only a
 * provider that reads pivot rows can serve it.
 */
final class PivotOrderShelfResource extends OrderShelfResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('picks', 'books')
                ->through(OrderPick::class)
                ->fields(Integer::make('rank'), Integer::make('position'))
                ->positionedBy('position')
                ->extractUsing(static fn(mixed $shelf): array => $shelf instanceof OrderShelf
                    ? \array_values(\array_map(static fn(OrderPick $pick): ?OrderBook => $pick->book, $shelf->picks->toArray()))
                    : [])
                ->withData()
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false)),
        ];
    }
}
