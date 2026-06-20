<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * An extension "arm" for {@see DoctrineSortHandler}: it appends the `ORDER BY` for
 * ONE custom {@see SortInterface} type to a Doctrine `QueryBuilder`. Implement it,
 * register it as a service (autoconfigured by this interface — no manual tag), and
 * the Doctrine provider consults every registered arm for any directive whose sort
 * is not a built-in {@see \haddowg\JsonApi\Resource\Sort\SortByField} (first
 * {@see supports()} match wins) before raising core's
 * {@see \haddowg\JsonApi\Resource\Sort\UnsupportedSort}.
 *
 * Directives arrive most-significant first and each arm appends its term in turn, so
 * a custom directive participates in the composite `ORDER BY` (primary, secondary, or
 * tie-breaker) exactly as a field sort does — `apply()` must `addOrderBy`, never
 * `orderBy` (which would discard the earlier terms). This is the data-layer twin of
 * core's in-memory
 * {@see \haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface}; a portable
 * custom sort ships both.
 */
interface DoctrineSortArmInterface
{
    /**
     * Whether this arm orders by `$sort`. Keyed on the sort's concrete type
     * (`$sort instanceof MySort`) — one arm backs one sort value-object class.
     */
    public function supports(SortInterface $sort): bool;

    /**
     * Appends the `ORDER BY` term for `$sort` on `$alias` to `$query` in the
     * `$descending` direction (via `addOrderBy`, so earlier directives are kept).
     * Only called when {@see supports()} returned `true`.
     */
    public function apply(SortInterface $sort, QueryBuilder $query, bool $descending, string $alias): void;
}
