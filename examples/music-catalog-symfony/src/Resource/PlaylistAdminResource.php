<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToBuilder;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Hydrator\PlaylistHydrator;

/**
 * The `playlists` type on the admin server: {@see PlaylistResource} with `owner` writable,
 * since `users` is served here and an admin client can name the user to hand a playlist
 * to. Two servers serving one type through different classes is the per-server class
 * pattern in `docs/multi-server-and-testing.md`; each server reads only its own class's
 * attribute, so this one repeats the default class's.
 */
#[AsJsonApiResource(
    entity: Playlist::class,
    server: ['admin'],
    hydrator: PlaylistHydrator::class,
    securityUpdate: "is_granted('EDIT', object)",
    securityDelete: "is_granted('ROLE_ADMIN')",
    tags: ['Library'],
    operationScopes: ['delete' => ['library:write', 'library:admin']],
)]
final class PlaylistAdminResource extends PlaylistResource
{
    protected function owner(): BelongsToBuilder
    {
        return BelongsTo::make('owner', 'users')
            ->security(read: "is_granted('ROLE_ADMIN')")
            ->withoutRelatedEndpoint()
            ->exampleId('1');
    }
}
