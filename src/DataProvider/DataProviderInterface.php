<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;

/**
 * The read-half data-source SPI: the storage-agnostic contract the
 * {@see \haddowg\JsonApiBundle\Operation\ReadOperationHandler} delegates to for
 * `GET /{type}` and `GET /{type}/{id}`.
 *
 * A provider is resolved per resource type via
 * {@see DataProviderRegistry::forType()}: {@see supports()} tells the registry
 * which type(s) a provider answers for. Writes (create/update/delete) land in a
 * separate persister SPI in a later phase; this interface stays read-only.
 *
 * {@see fetchCollection()} already accepts the parsed
 * {@see QueryParameters} so the signature is stable across the filter/sort/
 * pagination work of later phases, even though the Phase-0 read path ignores it.
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
     */
    public function fetchOne(string $type, string $id): ?object;

    /**
     * The collection of resources of `$type`. The parsed query parameters are
     * passed for forward compatibility with later filter/sort/pagination work;
     * a Phase-0 provider may ignore them.
     *
     * @return iterable<object>
     */
    public function fetchCollection(string $type, QueryParameters $queryParameters): iterable;
}
