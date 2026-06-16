<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The read-half data-source SPI: the storage-agnostic contract the
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} delegates to for
 * `GET /{type}` and `GET /{type}/{id}`.
 *
 * A provider is resolved per resource type via
 * {@see DataProviderRegistry::forType()}: {@see supports()} tells the registry
 * which type(s) a provider answers for. Writes (create/update/delete) land in a
 * separate persister SPI in a later phase; this interface stays read-only.
 *
 * {@see fetchCollection()} receives a fully-resolved {@see CollectionCriteria}
 * (declared filter/sort vocabularies, requested query parameters, pagination
 * window) — the handler does the resolving, the provider only matches and
 * executes, sharing the matching via {@see CriteriaApplier} so every provider
 * agrees on the spec semantics and differs only in execution.
 *
 * `TEntity` is the domain-object type the provider yields — covariant, so a
 * single-model provider (`DataProviderInterface<Article>`) is substitutable
 * wherever a `DataProviderInterface<object>` is expected (the registry holds
 * the heterogeneous set that way). A multi-type provider like the Doctrine one
 * implements `DataProviderInterface<object>`.
 *
 * @template-covariant TEntity of object
 */
interface DataProviderInterface
{
    /**
     * Whether this provider answers for the given resource type.
     */
    public function supports(string $type): bool;

    /**
     * The single resource of `$type` with `$id`, or `null` when none exists
     * (the handler maps `null` to a JSON:API `404`).
     *
     * @return TEntity|null
     */
    public function fetchOne(string $type, string $id): ?object;

    /**
     * The collection of resources of `$type` satisfying `$criteria`: filtered
     * and sorted per the requested parameters, windowed when the criteria carry
     * a pagination window (in which case the result also carries the
     * pre-window total).
     *
     * @return CollectionResult<TEntity>
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   when a requested sort field is not declared
     */
    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult;

    /**
     * The related collection of `$relatedType` reachable from `$parent` through
     * `$relation` (a to-many), scoped to the parent then filtered, sorted and
     * windowed per `$criteria` — the related-endpoint twin of
     * {@see fetchCollection()}. The criteria carry the **related** type's declared
     * filter/sort vocabularies and the per-relation pagination window.
     *
     * The endpoint total is gated by the relation's
     * {@see RelationInterface::isCountable()} (bundle ADR 0052 / core ADR 0057): a
     * **countable** relation's windowed fetch computes the pre-window total and
     * returns it on the result ({@see CollectionResult::$total}), so the handler
     * emits `meta.page.total` + a `last` link as before; a **non-countable**
     * relation's windowed fetch is **count-free** — it runs no `COUNT`, returns a
     * `null` total with {@see CollectionResult::$windowed} `true` and
     * {@see CollectionResult::$hasMore} set (from probing one item past the window),
     * so the handler renders a count-free page (no `total`, no `last`; `next` driven
     * by `$hasMore`). An unwindowed fetch returns neither (a plain collection).
     *
     * @return CollectionResult<TEntity>
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   when a requested sort field is not declared
     */
    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult;

    /**
     * The related value(s) of `$relation` (a monomorphic to-many OR to-one) for a
     * whole PAGE of parents, each scoped/filtered/sorted/windowed per `$criteria`, as a
     * {@see RelatedBatch} keyed by parent wire id — the batched, page-at-a-time twin
     * of {@see fetchRelatedCollection()} the {@see RelationshipWindowBatcher} drives to
     * window a collection include and the {@see RelatedIncludeBatcher} drives to load a
     * whole `?include` tree without N+1 (bundle ADR 0061/0062).
     *
     * A **to-many** relation's per-parent result is its windowed page (or, in
     * plain-include fast-path mode — empty criteria + null window — its WHOLE related
     * set). A **to-one** relation's per-parent result is a 0-or-1
     * {@see CollectionResult} carrying its single target (the include arm, bundle ADR
     * 0062): the reference Doctrine provider projects each parent's target id as a
     * scalar (`IDENTITY(parent.<assoc>)`, no proxy init), loads the distinct targets in
     * ONE `WHERE id IN (:ids)` query, and partitions 1:1; the in-memory witness wraps
     * each parent's {@see RelationInterface::readValue()} object. A relation the
     * provider cannot batch (a computed/`extractUsing` column that is not a real
     * association, or a composite-id target) returns an empty {@see RelatedBatch}, so
     * the caller's write-back is a no-op and the relation renders lazily.
     *
     * Where {@see fetchRelatedCollection()} answers ONE parent and the
     * {@see RelationshipWindowBatcher} looped it M parents x N relations
     * (~`2*M*N` statements per page), this answers the whole page for one relation in
     * a single store round-trip — `$criteria` configures it exactly as the per-parent
     * fetch: a windowed include carries the merged related vocabulary + a page-1
     * window, so the result is each parent's page-1 slice.
     *
     * The reference Doctrine provider runs **Approach B**: one query scopes the
     * related entity to the whole page (`WHERE parentFk IN (:ids)`, the batched
     * generalisation of {@see Doctrine\RelationScope}) while projecting the parent
     * discriminator, applies the shared {@see CriteriaApplier} filters/sorts IN that
     * query, materializes the flat list, partitions it by parent in PHP, then runs the
     * shared {@see \haddowg\JsonApi\Collection\WindowExecutor} per partition — so a
     * partition's window slice / count-free `hasMore` / countable total is built with
     * no further query. The in-memory witness does the SAME algorithm per parent
     * (read related off the parent, apply criteria, window), so the two are
     * structurally equivalent. The over-fetch (a parent's whole related set is
     * materialized to render its page) is identical to the include preloader's; the
     * batch strictly REDUCES statement count.
     *
     * A parent with no related members is simply absent from the map — the
     * {@see RelatedBatch::for()} accessor fills it with an empty result. Keyed by each
     * parent's JSON:API (wire) id (the serializer's `getId()` / the store's `idOf()`),
     * exactly as {@see countRelated()} keys its map, so a caller reconciles a result
     * back to its parent object through the same wire-id resolution.
     *
     * A polymorphic to-many follows the same support matrix as
     * {@see fetchRelatedCollection()}: Doctrine throws (members span entity classes —
     * not one scoped query), the in-memory witness reads the mixed set off each parent.
     *
     * @param list<object> $parents the already-fetched page of parents (the handler holds it)
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   when a requested sort field is not declared
     */
    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch;

    /**
     * The cardinality of `$relation` (a countable to-many) for each parent in
     * `$parents`, as a `wire-id => count` map — the count-only batch seam the
     * {@see \haddowg\JsonApiBundle\DataProvider\RelationCountBatcher} drives for
     * `?withCount` (bundle ADR 0052). One grouped, pushed-down `COUNT` answers the
     * whole page of parents, so a collection render does not N+1; a single parent
     * is just a one-element batch.
     *
     * The count is over the relation's **filtered** set: `$criteria` carries the
     * merged related-collection filter vocabulary and the request's
     * `relatedQuery[<rel>][filter]` for this relation (no window, since a count needs
     * no page, and no sort, since order is irrelevant to a count) — exactly the
     * filters {@see fetchRelatedCollection()} would apply. In the common case the
     * relation carries no relatedQuery filter, so `$criteria` is empty and the count
     * is raw membership unchanged; when a `?withCount`-named relation also carries a
     * relatedQuery filter, the count reflects it, so the relationship-object total and
     * the related-collection endpoint total describe the SAME filtered set (bundle ADR
     * 0060). An unrecognised filter key still `400`s, since the count criteria carries
     * the merged vocabulary.
     *
     * `$type` is the **parent** resource type (the relation lives on the parent);
     * `$relation` carries the related type(s) and the backing association the
     * provider counts over. The map is keyed by each parent's JSON:API (wire) id —
     * the value the serializer renders — so the batcher can resolve a count back to
     * a parent object at render time. A parent whose filtered set is empty reports
     * `0` (not absent): the Doctrine provider zero-fills the page so a parent dropped
     * by the related-alias filter is restored to `0`, matching the in-memory witness.
     *
     * The reference Doctrine provider runs `SELECT <parentKey>, COUNT(<related>) …
     * WHERE <parentKey> IN (:pageIds) GROUP BY <parentKey>` reusing the same
     * related-collection scoping as {@see fetchRelatedCollection()} (so it never
     * materializes a collection), applying `$criteria`'s filters on the `related`
     * join alias; a pivot relation counts its association rows with the filters on the
     * far-member alias. The in-memory witness reads the related objects off each
     * parent, applies `$criteria`'s filters and counts the survivors. A
     * polymorphic to-many follows the same support matrix as
     * {@see fetchRelatedCollection()} — Doctrine throws (members span entity
     * classes), in-memory counts the mixed set.
     *
     * @param list<object> $parents the already-fetched page of parents (the handler holds it)
     *
     * @return array<int|string, int> `parentWireId => count` (an integer-PK wire id is a
     *                                numeric string, which PHP stores as an int array key — the
     *                                batcher reconciles it against each parent's string wire id)
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a relatedQuery filter key is not declared
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array;

    /**
     * Whether the single related object of a monomorphic TO-ONE `$relation` survives
     * `$criteria`'s (merged) filters — the to-one twin of {@see fetchRelatedCollection()},
     * answering "does this one related object match?" for the single-resource to-one
     * surfaces (the related endpoint `GET /{type}/{id}/{toOneRel}?filter[…]` and the
     * relationship endpoint `…/relationships/{toOneRel}?filter[…]`) and the
     * `relatedQuery[<toOneRel>][filter]` profile path on a single parent (bundle ADR
     * 0068). When it returns `false` the handler nulls the to-one (renders `data: null`
     * / null linkage / omits the include); when `true` it renders the related object
     * unchanged.
     *
     * `$criteria` carries the SAME relation-scoped ({@see RelationInterface::filters()})
     * + related-resource filter vocabulary the to-many related endpoint resolves (via
     * {@see RelationCriteriaFactory::criteriaFor()}, `includePivotFields: false` — a
     * to-one has no pivot), and never a window/sort (a single member has neither). The
     * in-memory witness wraps `$related` in a 1-element list and runs the shared
     * {@see CriteriaApplier}; the Doctrine reference runs a cheap `SELECT 1 … WHERE
     * id = :id` probe driving {@see Doctrine\DoctrineFilterHandler}, so column/operator/
     * cast semantics match the to-many endpoint exactly. The probe is read-only (no
     * flush); the handler write-back of `null` onto the parent property is the caller's
     * concern.
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     */
    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool;

    /**
     * The BATCHED twin of {@see relatedToOneMatches()} for a whole PAGE of parents — the
     * include/primary path of the `relatedQuery[<toOneRel>][filter]` profile, run ONCE
     * over the page so the include does not N+1 (bundle ADR 0068). For each parent it
     * answers whether that parent's single to-one target satisfies `$criteria`'s filters,
     * as a `wire-id => bool` map keyed exactly as {@see countRelated()} /
     * {@see fetchRelatedCollectionBatch()} (the serializer's `getId()` / the store's
     * `idOf()`), so the {@see RelationshipWindowBatcher} can reconcile each result back to
     * its parent object and `Accessor::set` the property to `null` when the target does
     * not match.
     *
     * The in-memory witness reads each parent's {@see RelationInterface::readValue()}
     * to-one and runs the same 1-element {@see CriteriaApplier} match per object, keyed by
     * `idOf($parent)`. The Doctrine reference projects each parent's to-one target id off
     * the managed parent (no proxy init, reusing the `toOneTargetId()`/`parentWireId()`
     * helpers built for {@see fetchRelatedCollectionBatch()}'s to-one arm), runs ONE
     * `SELECT id … WHERE id IN (:targetIds) AND <filters>` query, intersects, and maps
     * each parent wire id to whether its target id survived — O(1) store round-trips per
     * relation, not per parent. A parent whose to-one is `null` short-circuits to `false`
     * (no target to match) so it is nulled with no further effect.
     *
     * A parent absent from the map is treated by the caller as a no-match (nulled). A
     * polymorphic to-one is out of scope (no shared filter vocabulary), so this is only
     * ever called for a monomorphic to-one.
     *
     * @param list<object> $parents the already-fetched page of parents (the handler holds it)
     *
     * @return array<string, bool> `parentWireId => target-matches`
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     */
    public function relatedToOneMatchesBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array;

    /**
     * The EXISTING pivot meta for the members currently in `$parent`'s pivot
     * `$relation` — `relatedId => [pivotField => wire value]` — read straight from
     * storage with no filter or window. The validator folds a stored pivot row under
     * an incoming linkage member's meta so an existing-member partial pivot update
     * validates in the **update** (preserved-value) context while a genuinely-new
     * member still validates in the create (new-row) context (the merge-before-validate
     * pivot half, ADR 0050).
     *
     * A non-pivot relation, a pivot relation with no pivot fields, or a provider that
     * cannot store pivot data (the in-memory witness, a custom store) returns `[]` —
     * every incoming member is then treated as new (create context), the documented
     * boundary. The reference Doctrine provider reads the same association-entity rows
     * the pivot-read feature already projects, keyed by the related id.
     *
     * @param object $parent the already-loaded parent (the handler holds it); avoids a re-fetch
     *
     * @return array<string, array<string, mixed>> `relatedId => [pivotField => wire value]`
     */
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array;
}
