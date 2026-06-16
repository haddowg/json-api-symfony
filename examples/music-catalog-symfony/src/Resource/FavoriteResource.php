<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Favorite;

/**
 * The `favorites` resource type, mapped to its backing {@see Favorite} entity.
 *
 * It is the **polymorphic to-one witness** (seam 3, ADR 0032): `favoritable`
 * points at a track, album, or artist, and the member's serializer is resolved at
 * runtime from the related object's own type. The Doctrine provider resolves the
 * member from the entity's `targetType`/`targetId` pair onto the non-mapped
 * `$favoritable` property (the example's PolymorphicTest is the **first** Doctrine
 * functional witness of a polymorphic to-one); an empty target renders
 * `data: null`.
 *
 * Re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/FavoriteResource.php FavoriteResource}.
 */
#[AsJsonApiResource(entity: Favorite::class)]
final class FavoriteResource extends AbstractResource
{
    public static string $type = 'favorites';

    public function fields(): array
    {
        return [
            // Store-provided id (the example's default): the {@see Favorite} entity is
            // keyed by a database-assigned auto-increment integer, so a plain
            // `Id::make()` sets nothing on create and the DB assigns the id — read back
            // on the `201`.
            Id::make(),
            DateTime::make('favoritedAt')->readOnlyOnUpdate(),

            // Default relation reader: `user` reads the ManyToOne straight off the
            // entity.
            BelongsTo::make('user')->type('users'),
            // The polymorphic to-one: a custom relation resolver reads the resolved
            // `favoritable` member off the entity (the provider fills it from the
            // targetType/targetId pair). This is the one and only extractUsing in the
            // example — the natural place a polymorphic to-one needs a custom resolver.
            MorphTo::make('favoritable')
                ->types('tracks', 'albums', 'artists')
                ->extractUsing(static fn(mixed $favorite): ?object => $favorite instanceof Favorite ? $favorite->favoritable : null),
        ];
    }
}
