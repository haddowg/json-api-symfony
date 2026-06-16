<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * Everything a {@see DataProviderInterface} needs to answer a collection fetch,
 * resolved by the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * from the operation and the resource declaration so providers stay decoupled
 * from core's `AbstractResource` API:
 *
 * - {@see $queryParameters} — the request's parsed query-parameter groups;
 * - {@see $filters} / {@see $sorts} — the **declared** vocabularies the
 *   requested `filter[…]`/`sort` keys are matched against (the resource's
 *   `filters()` / `allSorts()`); execution stays in the provider's handlers;
 * - {@see $defaultSort} — the resource's `defaultSort()` directives, applied by
 *   the {@see CriteriaApplier} **only when the request carries no `sort`**; an
 *   explicit `?sort=` overrides it entirely. Each directive's sort must be one of
 *   the declared {@see $sorts} (validated by the applier exactly as a requested
 *   sort is), so a default flows through the provider's sort handler on the same
 *   path. A default order keeps an otherwise unsorted collection — and its
 *   pagination window — deterministic (core ADR 0044);
 * - {@see $window} — the pagination fetch window to push down to the store, or
 *   `null` for an unpaginated fetch. Carried as the polymorphic
 *   {@see WindowInterface}; a provider narrows to the concrete window types it
 *   can execute (count-based providers handle
 *   {@see \haddowg\JsonApi\Pagination\OffsetWindow}).
 * - {@see $aliasOf} — a **bundle-only** routing hint (no core change), mapping a
 *   filter/sort directive KEY to a non-root query alias the {@see CriteriaApplier}
 *   applies it on, so a single criteria can carry vocabulary spanning more than one
 *   alias of the same query. A key absent from the map resolves to the query's root
 *   alias, so the default `[]` keeps every directive on the root — exactly the
 *   single-alias behaviour every path had before. It is populated ONLY on the
 *   Doctrine pivot related-collection path (the pivot keys → the `pivot` join
 *   alias, see {@see RelationCriteriaFactory}); it is empty on every other Doctrine
 *   path and on EVERY in-memory path, so the alias-aware branches are provably inert
 *   off the pivot path (bundle ADR 0059).
 */
final readonly class CollectionCriteria
{
    /**
     * @param list<FilterInterface>     $filters     the filter vocabulary declared for the type
     * @param list<SortInterface>       $sorts       the sort vocabulary declared for the type
     * @param list<SortDirective>       $defaultSort the resource's default sort order, applied when no `sort` is requested
     * @param array<string, string>     $aliasOf     directive KEY → target query alias; an absent key resolves to the root alias (bundle-only, empty off the Doctrine pivot path)
     */
    public function __construct(
        public QueryParameters $queryParameters,
        public array $filters = [],
        public array $sorts = [],
        public ?WindowInterface $window = null,
        public array $defaultSort = [],
        public array $aliasOf = [],
    ) {}
}
