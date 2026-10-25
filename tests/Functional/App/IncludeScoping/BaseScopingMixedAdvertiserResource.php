<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A polymorphic view of the advertisers' `partners`, on its own type: a polymorphic
 * to-many reaching a scoped type cannot render its linkage, so only writes reach it.
 */
abstract class BaseScopingMixedAdvertiserResource extends AbstractResource
{
    protected const string PREFIX = '';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('mixedPartners', [static::PREFIX . 'agencies', static::PREFIX . 'members'])->storedAs('partners'),
            // Its first type is served unscoped and its second scoped: the agencies among
            // its members are narrowed to the ones the agency scope admits.
            MorphToMany::make('contacts', [static::PREFIX . 'advertisers', static::PREFIX . 'agencies'])->countable(),
        ];
    }
}
