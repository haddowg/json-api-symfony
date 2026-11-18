<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * `fk-items`, whose `owner` field holds its user's id in the `ownerKey` column.
 */
abstract class FkItemResource extends AbstractResource
{
    public static string $type = 'fk-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            Str::make('owner')->storedAs('ownerKey'),
        ];
    }

    public function filters(): array
    {
        return [Where::make('title')];
    }
}
