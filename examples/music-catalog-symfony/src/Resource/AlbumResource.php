<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;

/**
 * The `albums` resource type, mapped to its backing {@see Album} entity.
 *
 * It is the **multi-server witness** (ADR 0034): `server: ['default', 'admin']`
 * exposes it on BOTH the default surface (`/albums`) and the named `admin` surface
 * (`/admin/albums`), each route resolving its own `Server` (distinct `base_uri` in
 * the rendered links).
 *
 * Field/relation declarations are re-themed from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/AlbumResource.php AlbumResource}:
 * default includes by override ({@see getDefaultIncludedRelationships()}), a
 * directional `CompareField` (availableUntil GreaterThan availableFrom), and a
 * `Map` with a read-only child. The one Doctrine-specific divergence: where core's
 * in-memory app spreads the `releaseInfo` Map across flat `label`/`catalogueNumber`
 * columns, here it round-trips through a single JSON `releaseInfo` column via the
 * Map-level serialize/fill hooks (the pattern the bundle's Doctrine infra already
 * proves for a nested object stored as one JSON value).
 */
#[AsJsonApiResource(entity: Album::class, server: ['default', 'admin'])]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            Decimal::make('averageRating')->readOnly()->nullable(),
            // Closure bound: no future release dates, resolved at validation time so
            // it reflects "now" per request. Hydrated values normalise to UTC.
            DateTime::make('releasedAt')
                ->before(static fn(): \DateTimeImmutable => new \DateTimeImmutable())
                ->useTimezone('UTC')
                ->sortable(),
            Boolean::make('explicit'),
            Date::make('availableFrom')->nullable(),
            // Directional CompareField: the operator reads `availableUntil >
            // availableFrom` — this field is the LEFT operand.
            Date::make('availableUntil')
                ->nullable()
                ->compareWith('availableFrom', Comparison::GreaterThan),
            // The `releaseInfo` Map round-trips through a single JSON column (rather
            // than flat columns), via the Map-level serialize/fill hooks. The child
            // constraints still cascade to /data/attributes/releaseInfo/<child>
            // (catalogueNumber is read-only, so it never hydrates).
            Map::make('releaseInfo')->fields(
                Str::make('label'),
                Str::make('catalogueNumber')->readOnly(),
            )->serializeUsing(static function (mixed $model): mixed {
                $info = $model instanceof Album ? ($model->releaseInfo ?? null) : null;

                return $info === [] ? null : $info;
            })->fillUsing(static function (mixed $model, mixed $value): mixed {
                if ($model instanceof Album) {
                    $info = null;
                    if (\is_array($value)) {
                        $info = [];
                        foreach ($value as $key => $item) {
                            $info[(string) $key] = $item;
                        }
                    }
                    $model->releaseInfo = $info;
                }

                return $model;
            }),

            // Default relation reader: `artist` reads the ManyToOne and `tracks` the
            // OneToMany straight off the entity associations.
            BelongsTo::make('artist')->type('artists'),
            HasMany::make('tracks')
                ->type('tracks')
                ->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->linkageOnlyWhenLoaded(),
        ];
    }

    public function filters(): array
    {
        // WhereHas('tracks'): albums with at least one related track — the Doctrine
        // reference renders this as an EXISTS subquery over the same relation.
        return [
            WhereHas::make('tracks'),
        ];
    }

    /**
     * Default includes are realised purely as this override: `GET /albums/1` with
     * no `?include` yields the artist in `included`; an explicit `?include`
     * suppresses it.
     *
     * @return list<string>
     */
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['artist'];
    }

    /**
     * Object-aware so this resource can participate in a polymorphic relation:
     * only a real {@see Album} is an `albums` type.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof Album ? 'albums' : '';
    }
}
