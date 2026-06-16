<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * The storage-agnostic half of a collection fetch: matches the requested
 * `filter[…]` keys and `sort` fields against the declared vocabularies in a
 * {@see CollectionCriteria} and applies each match to a provider-native query
 * through the provider's core filter/sort handlers, threading the query value
 * through (`TQuery` is a `QueryBuilder` for Doctrine, a `list` in memory).
 *
 * Every provider runs this same matching, so the spec semantics — declared
 * filter defaults folded into the requested map (absent key → the filter's
 * declared default, a requested key always wins) via core's
 * {@see FilterDefaults}, unknown filter key → 400 {@see FilterParamUnrecognized},
 * sorting against an empty sort vocabulary → 400 {@see SortingUnsupported},
 * unknown sort field → 400 {@see SortParamUnrecognized}, `-` prefix →
 * descending — are decided once, and a provider only ever differs in
 * *execution*. That is what keeps the in-memory provider an attributable
 * conformance witness for the Doctrine one. Because defaults are folded before
 * the filters reach a handler, they also narrow the pre-window `COUNT` of a
 * paginated fetch, so totals describe the defaulted collection.
 */
final class CriteriaApplier
{
    /**
     * Applies the criteria's requested filters and sorts to `$query` and
     * returns the modified query. Pagination windowing is **not** applied here —
     * how a window executes (LIMIT/OFFSET vs `array_slice`) is the provider's.
     *
     * The handler parameters are contravariant in `TQuery`: a handler declared
     * for a broader query type (core's in-memory handlers operate on
     * `list<mixed>`) is a valid executor for any narrower query.
     *
     * @template TQuery
     *
     * @param TQuery                                       $query
     * @param FilterHandlerInterface<contravariant TQuery> $filterHandler
     * @param SortHandlerInterface<contravariant TQuery>   $sortHandler
     *
     * @return TQuery
     *
     * @throws FilterParamUnrecognized when a requested filter key is not declared
     * @throws SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws SortParamUnrecognized   when a requested sort field is not declared
     */
    public function apply(
        CollectionCriteria $criteria,
        mixed $query,
        FilterHandlerInterface $filterHandler,
        SortHandlerInterface $sortHandler,
    ): mixed {
        $query = $this->applyFilters($criteria, $query, $filterHandler);

        return $this->applySorts($criteria, $query, $sortHandler);
    }

    /**
     * @template TQuery
     *
     * @param TQuery                                       $query
     * @param FilterHandlerInterface<contravariant TQuery> $handler
     *
     * @return TQuery
     */
    private function applyFilters(CollectionCriteria $criteria, mixed $query, FilterHandlerInterface $handler): mixed
    {
        // Fold each declared filter's default into the requested map first: an
        // absent key takes its filter's declared default, a requested key wins.
        // A defaulted key is always declared (the default came from a declared
        // filter), so the unrecognised-key guard below only ever fires for a
        // genuinely undeclared requested key.
        $requested = FilterDefaults::apply($criteria->queryParameters->filter, $criteria->filters);

        foreach ($requested as $key => $value) {
            $filter = $this->filterFor($criteria->filters, (string) $key)
                ?? throw new FilterParamUnrecognized((string) $key);

            $query = $handler->apply($filter, $query, $value);
        }

        return $query;
    }

    /**
     * @template TQuery
     *
     * @param TQuery                                     $query
     * @param SortHandlerInterface<contravariant TQuery> $handler
     *
     * @return TQuery
     */
    private function applySorts(CollectionCriteria $criteria, mixed $query, SortHandlerInterface $handler): mixed
    {
        $requested = $criteria->queryParameters->sort;

        // No `?sort`: fall back to the resource's default order (if any). An
        // explicit `?sort=` overrides the default entirely — the default is never
        // appended to a requested sort (core ADR 0044). The defaults are still
        // matched against the declared sort vocabulary (same as a requested sort),
        // so a default naming an undeclared sort is a server-config error rather
        // than a silently dropped directive.
        if ($requested === []) {
            $directives = $this->defaultDirectives($criteria);

            return $directives === [] ? $query : $handler->apply($directives, $query);
        }

        if ($criteria->sorts === []) {
            throw new SortingUnsupported();
        }

        $directives = [];
        foreach ($requested as $field) {
            $descending = \str_starts_with($field, '-');
            $key = $descending ? \substr($field, 1) : $field;

            $sort = $this->sortFor($criteria->sorts, $key)
                ?? throw new SortParamUnrecognized($key);

            $directives[] = new SortDirective($sort, $descending);
        }

        // One composite call, most significant directive first — sorts do not
        // compose commutatively, so the handler owns the combination (core
        // ADR 0016).
        return $handler->apply($directives, $query);
    }

    /**
     * The resource's `defaultSort()` directives, validated against the declared
     * sort vocabulary exactly as a requested sort is: each default must name a
     * declared sort (else {@see SortParamUnrecognized}, a server-config error).
     * Returns the directives unchanged when valid — they already carry their
     * direction — so the same handler executes them as a requested sort.
     *
     * @return list<SortDirective>
     *
     * @throws SortParamUnrecognized when a default sort names an undeclared sort
     */
    private function defaultDirectives(CollectionCriteria $criteria): array
    {
        if ($criteria->defaultSort === []) {
            return [];
        }

        foreach ($criteria->defaultSort as $directive) {
            $key = $directive->sort->key();
            if ($this->sortFor($criteria->sorts, $key) === null) {
                throw new SortParamUnrecognized($key);
            }
        }

        return $criteria->defaultSort;
    }

    /**
     * @param list<FilterInterface> $filters
     */
    private function filterFor(array $filters, string $key): ?FilterInterface
    {
        foreach ($filters as $filter) {
            if ($filter->key() === $key) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * @param list<SortInterface> $sorts
     */
    private function sortFor(array $sorts, string $key): ?SortInterface
    {
        foreach ($sorts as $sort) {
            if ($sort->key() === $key) {
                return $sort;
            }
        }

        return null;
    }
}
