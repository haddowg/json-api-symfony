<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

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
     * The cardinality of `$relation` (a countable to-many) for each parent in
     * `$parents`, as a `wire-id => count` map — the count-only batch seam the
     * {@see \haddowg\JsonApiBundle\DataProvider\RelationCountBatcher} drives for
     * `?withCount` (bundle ADR 0052). One grouped, pushed-down `COUNT` answers the
     * whole page of parents, so a collection render does not N+1; a single parent
     * is just a one-element batch.
     *
     * `$type` is the **parent** resource type (the relation lives on the parent);
     * `$relation` carries the related type(s) and the backing association the
     * provider counts over. The map is keyed by each parent's JSON:API (wire) id —
     * the value the serializer renders — so the batcher can resolve a count back to
     * a parent object at render time. A parent with no count answer is simply
     * absent from the map (the seam then emits no `meta.total` for it).
     *
     * The reference Doctrine provider runs `SELECT <parentKey>, COUNT(<related>) …
     * WHERE <parentKey> IN (:pageIds) GROUP BY <parentKey>` reusing the same
     * related-collection scoping as {@see fetchRelatedCollection()} (so it never
     * materializes a collection); a pivot relation counts its association rows. The
     * in-memory witness counts the related objects read off each parent. A
     * polymorphic to-many follows the same support matrix as
     * {@see fetchRelatedCollection()} — Doctrine throws (members span entity
     * classes), in-memory counts the mixed set.
     *
     * @param list<object> $parents the already-fetched page of parents (the handler holds it)
     *
     * @return array<int|string, int> `parentWireId => count` (an integer-PK wire id is a
     *                                numeric string, which PHP stores as an int array key — the
     *                                batcher reconciles it against each parent's string wire id)
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
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
