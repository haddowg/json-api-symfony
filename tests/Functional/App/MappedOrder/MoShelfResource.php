<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * `books` and `tags` render in their association's mapped order. `booksByTitle` reads
 * the same association with a `defaultSort()`, which wins over the mapping.
 */
final class MoShelfResource extends AbstractResource
{
    public static string $type = 'moShelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('books', 'moBooks')->withData(),
            HasMany::make('tags', 'moTags')->withData(),
            HasMany::make('booksByTitle', 'moBooks')->storedAs('books')->withData()
                ->defaultSort(new SortDirective(SortByField::make('title'), descending: false)),
        ];
    }
}
