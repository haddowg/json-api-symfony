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
 * Re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/LibraryResource.php LibraryResource}.
 */
#[AsJsonApiResource(entity: Library::class)]
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
            BelongsTo::make('owner')->type('users'),
            MorphToMany::make('items')->types('tracks', 'albums', 'artists'),
        ];
    }
}
