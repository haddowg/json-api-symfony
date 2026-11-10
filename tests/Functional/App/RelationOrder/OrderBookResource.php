<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * Books read oldest first unless a relation says otherwise.
 */
final class OrderBookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            Integer::make('year')->sortable(),
        ];
    }

    public function defaultSort(): array
    {
        return [new SortDirective(SortByField::make('year'), descending: false)];
    }
}
