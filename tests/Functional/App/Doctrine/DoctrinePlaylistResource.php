<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The parent `playlists` type of the pivot fixture. Its `tracks` relation is a
 * {@see BelongsToMany} declaring the pivot fields (`position`, `addedAt`) the
 * backing {@see PlaylistTrackEntity} association entity carries — the Doctrine
 * adapter auto-detects that entity (exactly one to-many on {@see PlaylistEntity}
 * reaches the far type) and runs ONE DQL statement over it to render the pivot
 * values as `meta.pivot`, scope/filter/sort by them, and paginate.
 *
 * `extractUsing` maps the parent's `playlistTracks` association entities to their
 * far {@see TrackEntity} for the *relationship-linkage* endpoint (which renders the
 * whole association off the parent); the related-collection endpoint reads through
 * the pivot query, not this accessor. Paginated so the pivot page is windowed.
 */
#[AsJsonApiResource(entity: PlaylistEntity::class)]
final class DoctrinePlaylistResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('tracks')
                ->type('tracks')
                // `position` is a WRITABLE pivot field (settable from the linkage
                // meta, with a min(1) constraint); `addedAt` is server-owned —
                // readOnly(), so it is never written from meta and takes its default.
                ->fields(
                    Integer::make('position')->required()->min(1),
                    DateTime::make('addedAt')->readOnly(),
                )
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
                ->paginate(PagePaginator::make()),
        ];
    }
}
