<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * A person's `mentor` is a person too, so a related read of it renders a type that
 * declares a relation of the same name. `favourite` is a polymorphic to-one over the
 * same column.
 */
abstract class BaseRqPersonResource extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->sortable(),
            BelongsTo::make('mentor', 'people'),
            HasMany::make('books', 'books')->readOnly(),
            MorphTo::make('favourite', ['people', 'books'])->storedAs('mentor')->readOnly(),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof RqBook ? 'books' : static::$type;
    }

    public function filters(): array
    {
        return [Where::make('name')];
    }
}
