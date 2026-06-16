<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;

/**
 * The result of a batched related-collection fetch: one relation's windowed page
 * for **every parent in a page**, keyed by the parent's JSON:API (wire) id — the
 * collection/include twin of a single {@see CollectionResult}, the read-side
 * analogue of the count-only map {@see DataProviderInterface::countRelated()}
 * returns (bundle ADR 0061).
 *
 * {@see DataProviderInterface::fetchRelatedCollectionBatch()} produces it for ONE
 * relation across the whole page of parents in a single store round-trip
 * (Approach B: one query scopes the related entity to the page, the flat result is
 * partitioned by parent in PHP, and each partition is windowed through the shared
 * {@see \haddowg\JsonApi\Collection\WindowExecutor}). So a collection include that
 * windows N relations over M parents costs O(N) statements, not O(M*N) — the
 * batch retires the per-parent `fetchRelatedCollection` loop the
 * {@see RelationshipWindowBatcher} drove.
 *
 * Keyed by the parent **wire** id (the value the serializer renders / the store
 * identifies a parent by), exactly as {@see DataProviderInterface::countRelated()}
 * and {@see RelationCountBatcher} key their maps, so a caller reconciles a result
 * back to its parent object through the same wire-id resolution. A parent with no
 * related members is simply absent from the map; {@see for()} fills that gap with
 * an EMPTY {@see CollectionResult} so every parent in the page has a renderable
 * result.
 */
final readonly class RelatedBatch
{
    /**
     * @param array<int|string, CollectionResult<object>> $resultsByParentWireId the per-parent windowed page,
     *                                                                            keyed by parent wire id (an
     *                                                                            integer-PK wire id is a numeric
     *                                                                            string PHP stores as an int array
     *                                                                            key, exactly as {@see DataProviderInterface::countRelated()}
     *                                                                            keys its map; {@see for()} reconciles
     *                                                                            the lookup against a string wire id)
     */
    public function __construct(
        private array $resultsByParentWireId,
    ) {}

    /**
     * The windowed page for the parent with `$parentWireId`, or an EMPTY
     * {@see CollectionResult} when the parent has no related members in this batch
     * (it was absent from the partitioned result). The empty result carries no
     * window metadata (no total, not windowed, no further page), so a parent whose
     * related set is empty renders an empty relationship exactly as a per-parent
     * fetch of an empty set would.
     *
     * @return CollectionResult<object>
     */
    public function for(string $parentWireId): CollectionResult
    {
        return $this->resultsByParentWireId[$parentWireId] ?? new CollectionResult([]);
    }
}
