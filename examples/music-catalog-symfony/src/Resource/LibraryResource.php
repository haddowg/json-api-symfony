<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Library;

/**
 * The `libraries` resource type, mapped to its backing {@see Library} entity.
 *
 * It is the **polymorphic to-many witness** (seam 2, ADR 0032): `items` is a mixed
 * collection of tracks, albums, and artists. The Doctrine reference provider
 * **throws** on a `MorphToMany` (members span entity classes), so the NET-NEW
 * `LibraryItemsProvider` (built in the next phase) resolves the mixed members
 * across their per-type repositories and the resource renders each through its own
 * per-type serializer via a `PolymorphicSerializer`.
 *
 * It is also registered on the `admin` server, because {@see UserResource}'s
 * `library` relation exposes `GET /admin/users/{id}/library` and whitelists
 * `library` for inclusion. A related endpoint returns its target as primary data, so
 * the target type has to be registered wherever the parent is.
 *
 * Re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/LibraryResource.php LibraryResource}.
 */
#[AsJsonApiResource(entity: Library::class, server: ['default', 'admin'])]
final class LibraryResource extends AbstractResource
{
    public static string $type = 'libraries';

    public function fields(): array
    {
        return [
            Id::make(),

            // Default relation reader: `owner` reads the OneToOne inverse straight off
            // the entity; `items` reads the resolved mixed list (filled by the custom
            // provider) — each member renders through its own per-type serializer.
            //
            // `owner` targets `public-profiles` rather than the admin-only `users`: the
            // related endpoint `GET /libraries/{id}/owner` is served on the default
            // surface, which registers the curated view of the User row and not the full
            // record. The member name is unchanged.
            BelongsTo::make('owner', 'public-profiles'),
            MorphToMany::make('items', ['tracks', 'albums', 'artists']),
        ];
    }
}
