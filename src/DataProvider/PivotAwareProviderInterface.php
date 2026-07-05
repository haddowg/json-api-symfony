<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The pivot extension of {@see DataProviderInterface}: a provider that can fetch a
 * `belongsToMany` related collection over a Doctrine **association entity** (a join
 * modelled to carry pivot columns), returning both the page of far entities AND the
 * per-member pivot values.
 *
 * Only the reference {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}
 * implements it: a plain `#[ORM\ManyToMany]` join table cannot hold a pivot column,
 * so pivot data exists only when the join is an association entity, which only the
 * Doctrine adapter can query. The in-memory provider does NOT implement this
 * interface — a pivot relation's filter/sort keys are unrecognised there (`400`)
 * and no pivot meta renders (the documented in-memory boundary). The handler checks
 * `instanceof` and only takes the pivot path when both the provider supports it and
 * the relation is pivot-backed; otherwise the existing
 * {@see DataProviderInterface::fetchRelatedCollection()} path runs unchanged.
 *
 * @template-covariant TEntity of object
 */
interface PivotAwareProviderInterface
{
    /**
     * Whether `$relation` reached from a parent of `$relatedType` is a pivot-backed
     * relation this provider can fetch over an association entity — a
     * {@see \haddowg\JsonApi\Resource\Field\BelongsToMany} declaring pivot fields
     * whose association entity resolves. False routes the fetch to the plain
     * {@see DataProviderInterface::fetchRelatedCollection()} path.
     */
    public function supportsPivot(string $relatedType, RelationInterface $relation): bool;

    /**
     * The related collection of `$relatedType` reachable from `$parent` through the
     * pivot `$relation`, scoped to the parent then pivot-/related-filtered, sorted
     * and windowed per `$criteria` — executed as ONE DQL statement over the
     * association entity. Returns the page of far entities, the pre-window total
     * and the per-member pivot map (read from the same query), for the related
     * endpoint (`GET /{type}/{id}/{rel}`).
     *
     * A cursor (keyset) window ({@see \haddowg\JsonApi\Pagination\CursorWindow})
     * returns the cursor variant instead: the same page + pivot map, plus the
     * boundary cursor tokens the provider minted (count-free by design — the
     * handler narrows on it to build the cursor page).
     *
     * @return PivotCollectionResult<TEntity>|PivotCursorCollectionResult<TEntity>
     */
    public function fetchRelatedPivotCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): PivotCollectionResult|PivotCursorCollectionResult;

    /**
     * The pivot map for EVERY member of `$parent`'s pivot `$relation` (no window,
     * no filter) — `farMemberId => [field => typed value]` — for the
     * relationship-linkage endpoint (`GET /{type}/{id}/relationships/{rel}`), which
     * renders the whole association off the parent rather than a windowed page.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelatedPivotMap(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
    ): array;

    /**
     * The pivot map of EVERY member of `$relation` for a whole PAGE of `$parents`,
     * in ONE DQL statement scoped to the parent set — the batched twin of
     * {@see fetchRelatedPivotMap()} (no window, no filter) — so a primary-resource
     * document whose pivot relation's linkage data renders carries `meta.pivot` on
     * each identifier WITHOUT an N+1 of per-parent pivot reads (bundle ADR 0102).
     *
     * Returns `parentWireId => [farMemberId => [field => typed value]]`, where the
     * outer key is the wire id the PARENT serializer's `getId()` produces for the
     * SERVED `$parentType` (so the primary-document parent serializer can look up its
     * own parent's map) and the inner map keys each member by its far wire id (so the
     * {@see \haddowg\JsonApiBundle\Serializer\PivotMetaSerializer} looks up only the
     * members it actually renders — the member-id keying composes with any windowing
     * or filtering the linkage applies for free). A parent with no association rows
     * yields no entry (its lookup defaults to an empty map).
     *
     * `$parentType` is the served JSON:API type whose document is being rendered (NOT
     * reverse-resolved from the entity class): a Doctrine entity may back several
     * types with different id encoders, so keying the outer map by the served type's
     * encoder makes the key provably identical to the parent serializer's `getId()`
     * by construction.
     *
     * @param string       $parentType the served parent type whose encoder keys the outer map
     * @param list<object> $parents    the already-fetched page of parents
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function fetchRelatedPivotMapBatch(
        string $parentType,
        string $relatedType,
        array $parents,
        RelationInterface $relation,
    ): array;
}
