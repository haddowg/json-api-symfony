<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * `playlists` whose pivot `tracks` keep their members and have no `extractUsing()`, so
 * the value the relation reads off the playlist is its `PlaylistTrackEntity` rows, not
 * the tracks.
 */
#[AsJsonApiResource(entity: PlaylistEntity::class)]
final class FixedMembershipThroughPlaylistResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name')->build(),
            BelongsToMany::make('tracks', 'tracks')
                ->storedAs('playlistTracks')
                ->through(PlaylistTrackEntity::class)
                ->fields(Integer::make('position')->min(1)->build())
                ->cannotChangeMembership(),
        ];
    }
}
