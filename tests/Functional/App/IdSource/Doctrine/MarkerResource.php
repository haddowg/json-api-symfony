<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The allowClientId + generated-UUID witness (bundle ADR 0039): `uuid()` declares the
 * format (and its route/constraint), `allowClientId()` makes a client `data.id`
 * optional, and `generated()` mints a v4 UUID when none is supplied. A create with a
 * well-formed client UUID uses it; a create that omits the id has one minted; a create
 * with a malformed client id 422s on the `uuid()` format. Its `uuid()` id constraint is
 * also what a `counters` `marker` linkage id is validated against.
 */
#[AsJsonApiResource(entity: MarkerEntity::class)]
final class MarkerResource extends AbstractResource
{
    public static string $type = 'markers';

    public function fields(): array
    {
        return [
            Id::make()->uuid()->allowClientId()->generated(),
            Str::make('name')->required(),
        ];
    }
}
