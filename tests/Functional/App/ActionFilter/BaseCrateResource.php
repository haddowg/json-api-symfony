<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * The `filterCrates` type both kernels serve. Only an admin may list the collection, and
 * `lifecycle` defaults to `live`, so a retired crate is left out unless asked for.
 */
abstract class BaseCrateResource extends AbstractResource
{
    public static string $type = 'filterCrates';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('status'),
            Integer::make('weight'),
            Str::make('lifecycle'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('status'),
            Where::make('weight')->integer(),
            Where::make('name'),
            Where::make('lifecycle')->default('live'),
        ];
    }
}
