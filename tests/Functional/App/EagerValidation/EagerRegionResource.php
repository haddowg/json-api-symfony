<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The leaf related `regions` of the fail-loud eager-load validation fixture (bundle
 * ADR 0085): the type a {@see EagerBrandResource}'s to-one `region` points at, so the
 * two-segment ancestor path `tags.region` resolves to a real second-level type.
 */
final class EagerRegionResource extends AbstractResource
{
    public static string $type = 'regions';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
