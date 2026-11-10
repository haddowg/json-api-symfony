<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApi\Resource\Field\HasMany;

/**
 * Positions a relation by a field clients can write, which fails warm-up.
 */
final class WritablePositionShelfResource extends OrderShelfResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            HasMany::make('booksByAge', 'books')
                ->storedAs('books')
                ->positionedBy('year'),
        ];
    }
}
