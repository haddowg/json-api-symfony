<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * The member declaration. `agencyName` flattens the agency's name, and `party` is a
 * polymorphic view of the same to-one. `team`, `teamName`, `teamManagerName` and
 * `side` are their writable twins over the public `team` association.
 */
abstract class BaseScopingMemberResource extends AbstractResource
{
    protected const string PREFIX = '';

    public function getType(mixed $object): string
    {
        return $object instanceof ScopingAgency ? static::PREFIX . 'agencies' : static::$type;
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('agency', static::PREFIX . 'agencies'),
            Str::make('agencyName')->storedAs('name')->on('agency'),
            MorphTo::make('party', [static::PREFIX . 'agencies', static::PREFIX . 'members'])->storedAs('agency'),
            BelongsTo::make('team', static::PREFIX . 'agencies'),
            Str::make('teamName')->storedAs('name')->on('team'),
            Str::make('teamManagerName')->storedAs('name')->on('team.manager'),
            MorphTo::make('side', [static::PREFIX . 'agencies', static::PREFIX . 'members'])->storedAs('team'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('nameContains', 'name', 'like'),
        ];
    }
}
