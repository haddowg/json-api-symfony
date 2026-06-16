<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The generated-ULID witness (bundle ADR 0039): `ulid()->generated()` mints a
 * Crockford-base32 ULID when a create omits the id, through core's self-contained ULID
 * generator (no `symfony/uid` dependency). A create returns `201` with a ULID-shaped id.
 */
#[AsJsonApiResource(entity: BadgeEntity::class)]
final class BadgeResource extends AbstractResource
{
    public static string $type = 'badges';

    public function fields(): array
    {
        return [
            Id::make()->ulid()->generated(),
            Str::make('name')->required(),
        ];
    }
}
