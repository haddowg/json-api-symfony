<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * A provider that needs more than core's {@see \haddowg\JsonApi\Resource\Sort\KeysetSortInterface}
 * to order a cursor page by a custom sort, such as an arm that supplies the sort's
 * expression. The bundle asks it at `cache:warmup` about every sort a cursor-paged
 * collection declares, so a sort it can't order by fails the build instead of the
 * request. A provider that doesn't implement it orders by any keyset sort.
 */
interface KeysCursorSortsInterface
{
    /**
     * Whether a cursor page of `$type` can be ordered by `$sort`.
     */
    public function keysCursorSort(string $type, SortInterface $sort): bool;
}
