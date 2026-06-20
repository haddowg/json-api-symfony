<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `publishers` declaration of the flattened-attribute (`on()`)
 * conformance fixture (bundle ADR 0085): a sibling registered type a
 * {@see BaseFlattenBookResource}'s book carries a `publisher` FK to. It backs no
 * flattened attribute and is no longer eager-pinned; it stays only to keep the seeded
 * book graph realistic.
 */
abstract class BaseFlattenPublisherResource extends AbstractResource
{
    public static string $type = 'publishers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
