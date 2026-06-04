<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
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
 * Every provider runs this same matching, so the spec semantics — unknown
 * filter key → 400 {@see FilterParamUnrecognized}, sorting against an empty
 * sort vocabulary → 400 {@see SortingUnsupported}, unknown sort field → 400
 * {@see SortParamUnrecognized}, `-` prefix → descending — are decided once,
 * and a provider only ever differs in *execution*. That is what keeps the
 * in-memory provider an attributable conformance witness for the Doctrine one.
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
        foreach ($criteria->queryParameters->filter as $key => $value) {
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
        if ($requested === []) {
            return $query;
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
