<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The far (related) `medals` type of the request-aware-predicates fixture: a plain
 * id/title resource the to-many `badges.medals` relation links to. Shared between
 * the in-memory and Doctrine kernels so the relationship-mutation and include
 * assertions run identically on both providers.
 *
 * It exposes a read-only inverse `badges` to-many so a badge is reachable as an
 * INCLUDED resource (`GET /medals/1?include=badges`) and as the primary of a
 * RELATED read (`GET /medals/1/badges`) — the badge-serialization contexts the
 * hidden-`secret` negative assertion exercises beyond the single/collection read.
 */
abstract class BaseMedalResource extends AbstractResource
{
    public static string $type = 'medals';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            HasMany::make('badges')->type('badges')->withData()->readOnly(),
        ];
    }
}
