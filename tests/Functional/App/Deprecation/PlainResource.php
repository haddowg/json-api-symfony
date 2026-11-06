<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The fields of the types the slice deprecates whole, or not at all.
 */
abstract class PlainResource extends AbstractResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
