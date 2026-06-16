<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;

/**
 * A pivot-aware provider's answer to a related-collection fetch: the materialized
 * page of far (related) entities (already pivot/related-filtered, sorted and
 * windowed) plus the pre-window total, AND the pivot map for exactly those
 * members — `farMemberId => [pivotField => typed value]`, read from the SAME query
 * (the selected association entity), cast per the relation's declared pivot-field
 * types.
 *
 * It **extends** the core {@see CollectionResult} (the moved window-result value
 * object, core ADR 0061), carrying every shape the plain result does — items,
 * total, windowed, hasMore — plus the per-member {@see $pivotMap}. The handler's
 * existing pagination/render path therefore reads it as a `CollectionResult`
 * unchanged; {@see collection()} is retained for callers that want the plain view
 * explicitly.
 *
 * The handler renders the page through a
 * {@see \haddowg\JsonApiBundle\Serializer\PivotMetaSerializer} holding the map, so
 * each member's resource (related endpoint) and identifier (relationship endpoint)
 * carries its per-member pivot values as `meta.pivot`. There is no separate read:
 * the values come from the same single DQL statement that scoped, filtered, sorted
 * and windowed the collection.
 *
 * Like {@see CollectionResult}, a non-countable pivot relation paginates
 * **count-free** (bundle ADR 0052): the related-collection endpoint is gated by
 * `countable()` alone, so a non-countable `belongsToMany` endpoint runs no `COUNT`,
 * leaves the total null, and signals a further page through `windowed` + `hasMore`
 * (the data layer over-fetches by one). The handler reads those to build a
 * count-free page (`meta.page`/`links` without `total`/`last`).
 *
 * @template-covariant TEntity of object
 *
 * @extends CollectionResult<TEntity>
 */
final class PivotCollectionResult extends CollectionResult
{
    /**
     * @param iterable<TEntity>                   $items    the page of far entities
     * @param array<string, array<string, mixed>> $pivotMap `farMemberId => [field => typed value]`
     * @param ?int                                $total    the pre-window total (non-null exactly when windowed AND counted)
     * @param bool                                $windowed whether the fetch was windowed (a counted OR a count-free page); read only when the total is null, to tell a count-free page from a plain unpaginated collection
     * @param bool                                $hasMore  for a count-free page (total null, windowed true), whether a further page follows — drives the `next` link without a COUNT
     */
    public function __construct(
        iterable $items,
        public readonly array $pivotMap,
        ?int $total = null,
        bool $windowed = false,
        bool $hasMore = false,
    ) {
        parent::__construct($items, $total, $windowed, $hasMore);
    }

    /**
     * The plain {@see CollectionResult} view (items + total) the handler's existing
     * pagination/render path consumes unchanged.
     *
     * @return CollectionResult<TEntity>
     */
    public function collection(): CollectionResult
    {
        return new CollectionResult($this->items, $this->total);
    }
}
