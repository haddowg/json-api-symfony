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
 * `playlists` whose pivot-backed `tracks` keep their members, so the membership check
 * reads the far tracks of the playlist's association entities.
 */
#[AsJsonApiResource(entity: PlaylistEntity::class)]
final class FixedMembershipPlaylistResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name')->build(),
            BelongsToMany::make('tracks', 'tracks')
                ->through(PlaylistTrackEntity::class)
                ->fields(Integer::make('position')->min(1)->build())
                ->extractUsing(static function (mixed $playlist): array {
                    if (!$playlist instanceof PlaylistEntity) {
                        return [];
                    }

                    $tracks = [];
                    foreach ($playlist->playlistTracks as $playlistTrack) {
                        if ($playlistTrack->track !== null) {
                            $tracks[] = $playlistTrack->track;
                        }
                    }

                    return $tracks;
                })
                ->cannotChangeMembership(),
        ];
    }
}
