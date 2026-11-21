<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

/**
 * Parcels, which each caller sees only of their own, admins all of them.
 */
abstract class BaseParcelResource extends AbstractResource
{
    public static string $type = 'parcels';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner'),
            Str::make('label'),
            BelongsTo::make('crate', 'crates')->nullable(),
            HasMany::make('tags', 'tags'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('owner'),
            WhereThrough::make('tagName', 'tags.name'),
            WhereThrough::make('crateVault', 'crate.vault.code'),
        ];
    }
}
