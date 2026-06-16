<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * A pivot-aware provider's answer to a related-collection fetch: the materialized
 * page of far (related) entities (already pivot/related-filtered, sorted and
 * windowed) plus the pre-window total, AND the pivot map for exactly those
 * members — `farMemberId => [pivotField => typed value]`, read from the SAME query
 * (the selected association entity), cast per the relation's declared pivot-field
 * types.
 *
 * The handler renders the page through a
 * {@see \haddowg\JsonApiBundle\Serializer\PivotMetaSerializer} holding the map, so
 * each member's resource (related endpoint) and identifier (relationship endpoint)
 * carries its per-member pivot values as `meta.pivot`. There is no separate read:
 * the values come from the same single DQL statement that scoped, filtered, sorted
 * and windowed the collection.
 *
 * @template-covariant TEntity of object
 */
final readonly class PivotCollectionResult
{
    /**
     * @param iterable<TEntity>                     $items    the page of far entities
     * @param array<string, array<string, mixed>>   $pivotMap `farMemberId => [field => typed value]`
     * @param ?int                                  $total    the pre-window total (non-null exactly when windowed)
     */
    public function __construct(
        public iterable $items,
        public array $pivotMap,
        public ?int $total = null,
    ) {}

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
