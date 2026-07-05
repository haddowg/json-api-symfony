<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;

/**
 * The composite-attribute witness resource: an {@see Obj} `address` (typed object in
 * one value, per-child constraints) and a discriminated {@see OneOf} `block`. Both
 * carry child constraints so the validator bridge's cascade surfaces per-child `422`
 * pointers (`/data/attributes/address/city`, `/data/attributes/block/level`).
 */
final class CompositeWidgetResource extends AbstractResource
{
    public static string $type = 'composites';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            Obj::make('address')->nullable()->fields(
                Str::make('street')->required(),
                Str::make('city')->required(),
                Str::make('postcode')->required()->maxLength(10),
            ),
            OneOf::make('block')->nullable()->discriminator('kind')
                ->variant('heading', Str::make('text')->required(), Integer::make('level')->min(1)->max(6))
                ->variant('image', Url::make('src')->required(), Str::make('alt')),
        ];
    }
}
