<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * The bundle-only alias-aware extension a {@see \haddowg\JsonApi\Resource\Filter\FilterHandlerInterface}
 * implementation may offer so the shared {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier}
 * can push a declared filter down onto a NON-root alias of the query — the pivot
 * related-collection path applies the related vocabulary on the query root and the
 * pivot vocabulary on the joined `pivot` alias, all on the one builder (bundle ADR 0059).
 *
 * It is NOT a core change: core's `FilterHandlerInterface` is untouched, and the
 * in-memory handler does not implement this — the applier only ever requests a
 * non-root alias on the Doctrine pivot path, where the criteria's `aliasOf` map is
 * populated. The default (root) alias still flows through `apply()`.
 *
 * @template TQuery
 */
interface AliasAwareFilterHandler
{
    /**
     * Applies `$filter` to `$query` on the explicit `$alias` (rather than the query
     * root `apply()` targets), threading `$value` through, and returns the modified
     * query.
     *
     * @param TQuery $query
     *
     * @return TQuery
     */
    public function applyOn(FilterInterface $filter, mixed $query, mixed $value, string $alias): mixed;
}
