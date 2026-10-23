<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class BaseScopingAgencyResource extends AbstractResource
{
    protected const string PREFIX = '';

    public function getType(mixed $object): string
    {
        return $object instanceof ScopingMember ? static::PREFIX . 'members' : static::$type;
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->sortable(),
            HasMany::make('members', static::PREFIX . 'members'),
            HasOne::make('lead', static::PREFIX . 'members'),
        ];
    }
}
