<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The MULTI-TYPE-BACKED pivot-parent witness (bundle ADR 0102): a SECOND JSON:API
 * type over the SAME {@see PlaylistEntity} the plain `playlists`
 * ({@see DoctrinePlaylistResource}) backs — one entity, two types. Unlike `playlists`
 * (no encoder) this type attaches a {@see PrefixedPlaylistIdEncoder}, so its wire id
 * is a `pl-…` token distinct from the bare stringified storage id, and it is
 * registered AFTER `playlists` so an entity-class reverse-lookup would resolve the
 * FIRST-registered `playlists` (no encoder) — not this served type.
 *
 * It carries a `dataTracks` `belongsToMany` pivot relation that renders its linkage
 * data BY DEFAULT (`withData()`), so a plain `GET /encoded-playlists/pl-1` must carry
 * each member's `meta.pivot` on the primary relationships block. That only holds if
 * the batched per-parent pivot map keys its outer entry by the SERVED type's encoder
 * (matching this serializer's `getId()`); keyed by the reverse-resolved `playlists`
 * no-encoder it would key by the bare int and the decorator's `pl-…` lookup would
 * miss — silently dropping the pivot. This fixture pins that the served type's
 * encoder is used.
 */
#[AsJsonApiResource(entity: PlaylistEntity::class)]
final class EncodedPlaylistResource extends AbstractResource
{
    public static string $type = 'encoded-playlists';

    public function fields(): array
    {
        return [
            Id::make()
                ->encodeUsing(new PrefixedPlaylistIdEncoder())
                ->matchAs('pl-[0-9]+')->build(),
            Str::make('name'),
            BelongsToMany::make('dataTracks', 'tracks')
                ->through(PlaylistTrackEntity::class)
                ->fields(
                    Integer::make('position')->required()->min(1)->build(),
                    DateTime::make('addedAt')->readOnly()->build(),
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
                ->withData(),
        ];
    }
}
