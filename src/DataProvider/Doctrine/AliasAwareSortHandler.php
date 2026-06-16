<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * The bundle-only alias-aware extension a {@see \haddowg\JsonApi\Resource\Sort\SortHandlerInterface}
 * implementation may offer so the shared {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier}
 * can append a single sort directive on a NON-root alias of the query. The pivot
 * related-collection path builds the whole `ORDER BY` in the request's directive
 * order across two aliases — a pivot key on the joined `pivot` alias, a related key
 * on the root — so a pivot-first `?sort` stays the primary key (bundle ADR 0059).
 *
 * It is NOT a core change: core's `SortHandlerInterface` is untouched, and the
 * in-memory handler does not implement this — the applier only ever requests a
 * non-root alias (and the per-directive ordering) on the Doctrine pivot path, where
 * the criteria's `aliasOf` map is populated. The default (root) alias and the
 * single composite call still flow through `apply()`.
 *
 * @template TQuery
 */
interface AliasAwareSortHandler
{
    /**
     * Appends the `$directives` to `$query`'s ORDER BY on the explicit `$alias`
     * (rather than the query root `apply()` targets), most significant first, and
     * returns the modified query.
     *
     * @param list<SortDirective> $directives
     * @param TQuery              $query
     *
     * @return TQuery
     */
    public function applyOn(array $directives, mixed $query, string $alias): mixed;
}
