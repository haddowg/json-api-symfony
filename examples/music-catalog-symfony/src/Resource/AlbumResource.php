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
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
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
            //
            // A relation-scoped `withFilters()` on the TO-ONE `artist` is the witness
            // for null-a-to-one-when-a-relation-filter-excludes-its-target (bundle ADR
            // 0068): `filter[name]` (the related `artists.name` column) is reachable on
            // the three to-one read surfaces — `GET /albums/{id}/artist?filter[name]=…`,
            // `…/relationships/artist?filter[name]=…`, and `relatedQuery[artist][filter]
            // [name]=…` on a primary request. When the filter EXCLUDES the album's
            // artist the to-one renders `data: null` (and drops from `included[]`); a
            // `[sort]`/`[page]` on a to-one path stays a 400. Dual-provider.
            BelongsTo::make('artist')
                ->type('artists')
                ->withFilters(Where::make('name', 'name')),
            // Relation-scoped filters/sorts (bundle ADR 0044): `withFilters`/
            // `withSorts` augment the related-collection endpoint
            // `GET /albums/{id}/tracks` ONLY — they are merged on top of the related
            // `tracks` resource's own vocabulary there, but are absent from the
            // primary `/tracks` collection (its own `like`/`explicit`/`genres`
            // filters and `title`/`trackNumber` sorts are all `/tracks` knows).
            //
            //  - `filter[longerThan]` narrows the album's tracks to those whose
            //    `length_seconds` exceeds the value — a contextual filter that only
            //    makes sense when listing one album's tracks, so it lives on the
            //    relation, not the `tracks` type. Its `->integer()` value constraint
            //    (core ADR 0055, bundle ADR 0048) is validated before the
            //    related-collection fetch reaches the provider, so a mistyped
            //    `filter[longerThan]=banana` is a clean `400` (`FILTER_VALUE_INVALID`,
            //    `source.parameter`) instead of the provider's unhelpful default on
            //    the integer `length_seconds` column (a silent non-match on sqlite, or
            //    a Doctrine PDO `500` on a strict driver) — and only this
            //    client-supplied value is checked, never an author-set `default()`.
            //  - `sort=duration` orders them by `length_seconds`. Neither `longerThan`
            //    nor `duration` is a `/tracks` key, so `GET /tracks?filter[longerThan]`
            //    or `?sort=duration` is a 400 — proving the scope.
            //
            // Both name an entity column on the RELATED `Track` (the common case), so
            // they apply out of the box through the same criteria the providers
            // already honour. A PIVOT/join-table column (e.g. a many-to-many
            // `position` on `tracks.playlists`) is NOT auto-wired — that needs a
            // custom FilterHandler/SortHandler supplied through the provider seam; the
            // relation's `withFilters`/`withSorts` only names the key.
            HasMany::make('tracks')
                ->type('tracks')
                ->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->withFilters(Where::make('longerThan', 'length_seconds', '>')->integer())
                ->withSorts(SortByField::make('duration', 'length_seconds'))
                // Countable (bundle ADR 0052): the related-collection endpoint emits
                // meta.page.total + a last link, and ?withCount=tracks activates the
                // relationship-object meta.total on an album.
                ->countable()
                ->dataOnlyWhenLoaded(),
        ];
    }

    public function filters(): array
    {
        return [
            // WhereHas('tracks'): albums with at least one related track — the Doctrine
            // reference renders this as an EXISTS subquery over the same relation.
            WhereHas::make('tracks'),
            // WhereThrough('artist.name'): the constrained-existence DOTTED-TRAVERSAL
            // filter (core ADR 0063, bundle ADR 0069). `filter[artist.name]=Radiohead`
            // keeps albums whose `artist` relation's `name` matches — a portable
            // EXISTS-ANY semi-join that works on BOTH providers (the in-memory provider
            // traverses the object graph; the Doctrine reference renders it as a
            // correlated EXISTS subquery rooted on the related `Artist`, never a
            // fetch-join, so it neither hydrates the relation nor multiplies rows). The
            // wire key carries the dots; the operator defaults to `=`.
            WhereThrough::make('artist.name'),
        ];
    }

    /**
     * Newest first by default: with no `?sort` the albums collection is ordered by
     * `releasedAt` descending through the Doctrine sort handler (so OK Computer
     * (1997) precedes Dummy (1994)), keeping the unsorted collection — and its
     * pagination — deterministic. An explicit `?sort=…` overrides this entirely
     * (core ADR 0044).
     *
     * @return list<SortDirective>
     */
    public function defaultSort(): array
    {
        return [
            new SortDirective(SortByField::make('releasedAt'), descending: true),
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
