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
     * filter/sort vocabularies and the per-relation pagination window; a windowed
     * fetch also carries the pre-window total.
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
}
