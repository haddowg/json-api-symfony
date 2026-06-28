<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
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
 *
 * It declares a SECOND pivot relation `orderedTracks` over the SAME
 * {@see PlaylistTrackEntity}, left **non-countable**, as the count-free pivot-endpoint
 * witness (bundle ADR 0052): its related collection paginates with no `COUNT`, so the
 * page carries no `meta.page.total` and no `last` link — the universal `countable()`
 * gate applies to the pivot path exactly as to the plain path. Because both relations
 * now reach the same far type through the same association entity, each pins it with
 * `->through()` (the auto-detection witnesses are the example app and
 * {@see \haddowg\JsonApiBundle\Tests\DataProvider\Doctrine\PivotAssociationResolverTest}).
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
            BelongsToMany::make('tracks', 'tracks')
                ->through(PlaylistTrackEntity::class)
                // `position` is a WRITABLE pivot field (settable from the linkage
                // meta, with a min(1) constraint); `weight` is a second WRITABLE int
                // constrained to be >= `position` — a cross-pivot-field rule that, on a
                // partial update, must evaluate against the MERGED pivot (the stored
                // position folded under the incoming weight); `addedAt` is server-owned
                // — readOnly(), so it is never written from meta and takes its default.
                ->fields(
                    Integer::make('position')->required()->min(1),
                    Integer::make('weight')->compareWith('position', Comparison::GreaterThanOrEqual),
                    DateTime::make('addedAt')->readOnly(),
                    // A HIDDEN pivot field (core hidden() gates rendering only): it is
                    // filterable via `pivot.note` below, yet never appears in the rendered
                    // pivot meta — the provider skips it from the SELECT and the pivot map.
                    Str::make('note')->hidden(),
                )
                // Pivot filters are AUTHOR-DECLARED via the `pivot.` column prefix
                // (bundle ADR 0067): a filter whose column starts with `pivot.` targets
                // the association-entity join. The value cast auto-resolves from the
                // pivot field backing the stripped column (so `addedAfter` coerces the
                // wire value to a DateTime exactly as the field renders it). The filter
                // KEY is independent of the column — `addedAfter` filters `pivot.addedAt`.
                ->withFilters(
                    Where::make('position', 'pivot.position'),
                    Where::make('weight', 'pivot.weight'),
                    Where::make('positionGte', 'pivot.position', '>='),
                    WhereIn::make('positionIn', 'pivot.position'),
                    Where::make('addedAfter', 'pivot.addedAt', '>'),
                    // A filter over the HIDDEN `note` pivot field: a hidden pivot field is
                    // queryable via the `pivot.` prefix (the filter reads the column on the
                    // `pivot` alias, not the rendered scalar) even though it never renders.
                    Where::make('noteIs', 'pivot.note'),
                )
                ->extractUsing($this->extractTracks())
                ->paginate(PagePaginator::make())
                // Countable (bundle ADR 0052): the Doctrine provider counts the
                // ASSOCIATION-entity rows per parent, so ?withCount=tracks reflects
                // duplicate membership (a track at two positions counts twice).
                ->countable(),

            // The count-free pivot witness (bundle ADR 0052): the SAME association
            // entity, left NON-countable. Its related collection paginates with no
            // COUNT — no meta.page.total, no `last` — exactly as the plain non-countable
            // path does, proving the universal countable() gate reaches the pivot path.
            BelongsToMany::make('orderedTracks', 'tracks')
                ->through(PlaylistTrackEntity::class)
                ->fields(
                    Integer::make('position')->required()->min(1),
                    DateTime::make('addedAt')->readOnly(),
                )
                ->withFilters(Where::make('position', 'pivot.position'))
                ->extractUsing($this->extractTracks())
                ->paginate(PagePaginator::make()),

            // The DEFAULT-RENDERED-LINKAGE pivot witness (bundle ADR 0102): the SAME
            // association entity, but `withData()` so its linkage data renders on a
            // PRIMARY document with NO `?include`. It proves a primary-resource document
            // carries each member's `meta.pivot` on the relationships block wherever the
            // pivot relation's linkage data renders — closing the gap where pivot rode
            // the related/relationship endpoints but not the primary document.
            BelongsToMany::make('dataTracks', 'tracks')
                ->through(PlaylistTrackEntity::class)
                ->fields(
                    Integer::make('position')->required()->min(1),
                    DateTime::make('addedAt')->readOnly(),
                )
                ->extractUsing($this->extractTracks())
                ->withData(),

            // The CONDITIONALLY-HIDDEN pivot witness (bundle ADR 0102): the SAME
            // association entity, `withData()` so its linkage WOULD render, but
            // `hidden(fn …)` makes it hidden FOR THIS REQUEST. A per-request hidden
            // relation is not UNCONDITIONALLY hidden, so it still flows to the render
            // loop where core's getRelationships() excludes it (isHiddenFor); the pivot
            // decorator must honour that exclusion rather than re-add the relation (and
            // its pivot meta) the author hid. It is hidden for every request here, so a
            // plain GET /playlists/1 must omit `hiddenDataTracks` entirely.
            BelongsToMany::make('hiddenDataTracks', 'tracks')
                ->through(PlaylistTrackEntity::class)
                ->fields(
                    Integer::make('position')->required()->min(1),
                    DateTime::make('addedAt')->readOnly(),
                )
                ->extractUsing($this->extractTracks())
                ->withData()
                ->hidden(static fn(mixed $model, JsonApiRequestInterface $request): bool => true),
        ];
    }

    /**
     * Maps the parent's `playlistTracks` association entities to their far
     * {@see TrackEntity}, shared by both pivot relations' `extractUsing` (the
     * relationship-linkage endpoint reads the whole association off the parent).
     *
     * @return \Closure(mixed): list<TrackEntity>
     */
    private function extractTracks(): \Closure
    {
        return static function (mixed $playlist): array {
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
        };
    }
}
