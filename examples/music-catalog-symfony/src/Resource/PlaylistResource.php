<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Uuid;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Hydrator\PlaylistHydrator;

/**
 * The `playlists` resource type, mapped to its backing {@see Playlist} entity.
 *
 * It is the **hydrator-override witness** (ADR 0023): `hydrator:
 * PlaylistHydrator::class` delegates writes to a hand-written hydrator (with a
 * bound constructor arg, proving DI resolution) while this resource still
 * serializes reads.
 *
 * It is also the **UUID id-strategy** demonstrator (bundle ADR 0039):
 * `Id::make()->uuid()->generated()` keys the {@see Playlist} on a string UUID the
 * *app* mints when a create omits the id, while the custom hydrator additionally
 * accepts a well-formed client `id` (the `uuid()` format both pins the route `{id}`
 * shape and validates a client-supplied id on the wire).
 *
 * Field/relation declarations are re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/PlaylistResource.php PlaylistResource}:
 * a UUID id; a read-only `slug` derived from `title` by the custom hydrator; a
 * `belongsTo` owner; and a pivot-backed `belongsToMany` `tracks` whose related
 * collection paginates two-per-page.
 */
#[AsJsonApiResource(entity: Playlist::class, hydrator: PlaylistHydrator::class)]
final class PlaylistResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            // A UUID id: the app mints a v4 UUID via generated() when a create omits
            // the id, and the custom hydrator also accepts a well-formed client UUID
            // (uuid() pins the route shape and validates a wire id).
            Id::make()->uuid()->generated(),
            Str::make('title')->required(),
            // Derived from title by the custom hydrator, so read-only on the wire.
            Slug::make('slug')->readOnly(),
            Boolean::make('public'),
            Uuid::make('externalId')->nullable(),

            // Default relation reader: `owner` reads the ManyToOne and `tracks` the
            // ManyToMany straight off the entity associations.
            BelongsTo::make('owner')->type('users'),
            BelongsToMany::make('tracks')
                ->type('tracks')
                ->fields(['position' => 'integer', 'addedAt' => 'datetime'])
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
        ];
    }
}
