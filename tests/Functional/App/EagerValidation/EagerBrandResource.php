<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The related `brands` of the fail-loud eager-load validation fixture (bundle ADR
 * 0085): the type every {@see BaseEagerProductResource} relation points at. It
 * declares a to-one `region`, so a product's `region.region` is a valid two-hop to-one
 * chain (the SAFE case) and a product's `tags.region` is a two-segment chain whose
 * FIRST segment (`tags`, a to-many) makes the validator throw even though it is an
 * ANCESTOR, not the leaf.
 */
final class EagerBrandResource extends AbstractResource
{
    public static string $type = 'brands';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('region', 'regions'),
        ];
    }
}
