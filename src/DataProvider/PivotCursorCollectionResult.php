<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CursorCollectionResult;

/**
 * A pivot-aware provider's answer to a cursor (keyset) related-collection fetch:
 * the windowed page of far (related) entities plus the boundary cursor tokens the
 * provider minted for them, AND the pivot map for exactly those members —
 * `farMemberId => [pivotField => typed value]`, read from the SAME query.
 *
 * It **extends** the core {@see CursorCollectionResult} (so the handler's cursor
 * narrow — `instanceof CursorCollectionResult` — and the count-free early-return
 * guard read it unchanged: total null, windowed true, the minted
 * `cursorBefore`/`cursorAfter` + has-flags), carrying the per-member
 * {@see $pivotMap} exactly as {@see PivotCollectionResult} carries it for the
 * offset page. The handler renders the page through a
 * {@see \haddowg\JsonApiBundle\Serializer\PivotMetaSerializer} holding the map, so
 * each member's resource (related endpoint) and identifier (relationship endpoint)
 * carries its per-member pivot values as `meta.pivot`.
 *
 * A cursor page is count-free BY DESIGN (the keyset strategy never derives a
 * total), so unlike {@see PivotCollectionResult} there is no counted variant.
 *
 * @template-covariant TEntity of object
 *
 * @extends CursorCollectionResult<TEntity>
 */
final class PivotCursorCollectionResult extends CursorCollectionResult
{
    /**
     * @param iterable<TEntity>                   $items        the windowed page of far entities
     * @param array<string, array<string, mixed>> $pivotMap     `farMemberId => [field => typed value]`
     * @param ?string                             $cursorBefore the encoded `prev` boundary (the first row's cursor), or null when the page is empty
     * @param ?string                             $cursorAfter  the encoded `next` boundary (the last row's cursor), or null when the page is empty
     * @param bool                                $hasPrevious  whether a previous page exists (drives the `prev` link)
     * @param bool                                $hasMore      whether a following page exists (drives the `next` link)
     */
    public function __construct(
        iterable $items,
        public readonly array $pivotMap,
        ?string $cursorBefore = null,
        ?string $cursorAfter = null,
        bool $hasPrevious = false,
        bool $hasMore = false,
    ) {
        parent::__construct($items, $cursorBefore, $cursorAfter, $hasPrevious, $hasMore);
    }
}
