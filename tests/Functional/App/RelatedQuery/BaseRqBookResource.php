<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

abstract class BaseRqBookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            BelongsTo::make('writer', 'people'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof RqPerson ? 'people' : static::$type;
    }

    public function filters(): array
    {
        return [Where::make('title'), WhereIdIn::make()];
    }
}
