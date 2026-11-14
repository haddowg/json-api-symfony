<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use haddowg\JsonApiBundle\DataProvider\Doctrine\AliasAwareFilterHandler;
use haddowg\JsonApiBundle\DataProvider\Doctrine\AliasAwareSortHandler;

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
 * {@see FilterDefaults}, filter keys and filter families matched through core's
 * {@see FilterMatcher} with a list filter's value read as its members, unknown
 * filter key → 400 {@see FilterParamUnrecognized}, sorting against an empty sort vocabulary → 400 {@see SortingUnsupported},
 * unknown sort field → 400 {@see SortParamUnrecognized}, `-` prefix →
 * descending, sort families through core's {@see SortMatcher} — are decided
 * once, and a provider only ever differs in *execution*. That is what keeps the
 * in-memory provider an attributable conformance witness for the Doctrine one.
 * Because defaults are folded before the filters reach a handler, they also
 * narrow the pre-window `COUNT` of a paginated fetch, so totals describe the
 * defaulted collection.
 *
 * The applier is **alias-aware** but inert unless the criteria carries a non-empty
 * {@see CollectionCriteria::$aliasOf} map (only the Doctrine pivot related-collection
 * path populates it) or the caller passes an explicit `$defaultAlias` to
 * {@see apply()}. When a directive's key maps to a non-root alias, the applier
 * pushes it down through the handler's bundle {@see AliasAwareFilterHandler}/{@see AliasAwareSortHandler}
 * capability on that alias; with an empty map and no `$defaultAlias` every directive
 * routes to the root exactly as before — so every non-pivot fetch path and the entire
 * in-memory provider are byte-identical. The `$defaultAlias` is the
 * count seam: the Doctrine `?withCount` count roots on the parent and joins the
 * related entity as `related`, so it passes `related` as the default alias to apply
 * every related filter on the join — not the parent root — matching the in-memory
 * witness's per-parent filtered count. An explicit per-key
 * `aliasOf` entry still wins over `$defaultAlias`. Sorts do not compose commutatively,
 * so an empty map with no `$defaultAlias` keeps the single composite handler call (the
 * in-memory stable multi-key sort) while a non-empty map or a
 * `$defaultAlias` applies the directives one at a time in request order across the
 * resolved aliases, reproducing the pivot path's cross-alias `ORDER BY` exactly.
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
     * @throws FilterValueInvalid      when a list filter's value is not a list it accepts
     * @throws SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws SortParamUnrecognized   when a requested sort field is not declared
     */
    public function apply(
        CollectionCriteria $criteria,
        mixed $query,
        FilterHandlerInterface $filterHandler,
        SortHandlerInterface $sortHandler,
        ?string $defaultAlias = null,
    ): mixed {
        $query = $this->filter($criteria, $query, $filterHandler, $defaultAlias);

        return $this->applySorts($criteria, $query, $sortHandler, $defaultAlias);
    }

    /**
     * Applies only the criteria's requested filters, with the declared defaults folded
     * in, and leaves sorting and windowing alone. A collection-scope custom action reads
     * its rows through this.
     *
     * @template TQuery
     *
     * @param TQuery                                       $query
     * @param FilterHandlerInterface<contravariant TQuery> $filterHandler
     *
     * @return TQuery
     *
     * @throws FilterParamUnrecognized when a requested filter key is not declared
     * @throws FilterValueInvalid      when a list filter's value is not a list it accepts
     */
    public function applyFilters(CollectionCriteria $criteria, mixed $query, FilterHandlerInterface $filterHandler): mixed
    {
        return $this->filter($criteria, $query, $filterHandler, null);
    }

    /**
     * @template TQuery
     *
     * @param TQuery                                       $query
     * @param FilterHandlerInterface<contravariant TQuery> $handler
     *
     * @return TQuery
     */
    private function filter(CollectionCriteria $criteria, mixed $query, FilterHandlerInterface $handler, ?string $defaultAlias): mixed
    {
        // Fold each declared filter's default into the requested map first: an
        // absent key takes its filter's declared default, a requested key wins.
        // A defaulted key is always declared (the default came from a declared
        // filter), so the unrecognised-key guard below only ever fires for a
        // genuinely undeclared requested key.
        $requested = FilterDefaults::apply($criteria->queryParameters->filter, $criteria->filters);

        // A filter family resolves to the concrete filter keyed by the full requested
        // key, so the key routes through `aliasOf` as is. Each matched filter's
        // traversal path resolves against the type the filters run against.
        foreach (FilterMatcher::directives($requested, $criteria->filters, $criteria->request) as $directive) {
            $key = $directive->key();
            $filter = $criteria->paths?->resolve($directive->filter) ?? $directive->filter;
            $value = $directive->value;

            // A key routes to its target alias when the criteria declares one
            // (only the Doctrine pivot path does), else to the caller's
            // `$defaultAlias` when one is given (the Doctrine ?withCount count
            // applies every related filter on the `related` join alias, not the
            // `parent` root), else to the query root through the unchanged
            // apply() — every non-pivot fetch path and the whole in-memory provider.
            $alias = $criteria->aliasOf[$key] ?? $defaultAlias;
            if ($alias !== null && !$handler instanceof AliasAwareFilterHandler) {
                throw new \LogicException(\sprintf(
                    'Filter "%s" is routed to alias "%s", but %s is not alias-aware.',
                    $key,
                    $alias,
                    $handler::class,
                ));
            }

            $apply = static fn(): mixed => $alias === null || !$handler instanceof AliasAwareFilterHandler
                ? $handler->apply($filter, $query, $value)
                : $handler->applyOn($filter, $query, $value, $alias);
            $query = $handler instanceof ReadsFilterPathsInterface && $criteria->paths !== null
                ? $handler->withPaths($criteria->paths, $apply)
                : $apply();
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
    private function applySorts(CollectionCriteria $criteria, mixed $query, SortHandlerInterface $handler, ?string $defaultAlias): mixed
    {
        $requested = $criteria->queryParameters->sort;

        // No `?sort`: fall back to the default order (if any). An explicit `?sort=`
        // overrides the default entirely — the default is never appended to a
        // requested sort (core docs/sorts.md). The defaults are still matched against
        // the declared sort vocabulary (same as a requested sort), so a default naming
        // an undeclared sort is a server-config error rather than a silently dropped
        // directive. A relation's default may name a pivot field, which routes through
        // its `aliasOf` entry exactly as a requested pivot sort does; every other key
        // routes to `$defaultAlias` when the caller supplies one (the batched
        // parent-rooted related fetch roots on the PARENT and applies the default order
        // on the `related` join alias), else to the query root. A count carries no sort
        // and no default order, so this returns the query untouched on that path.
        if ($requested === []) {
            $directives = \array_map(
                static fn(SortDirective $directive): array => [$directive->sort->key(), $directive],
                $this->defaultDirectives($criteria),
            );
            if ($directives === []) {
                return $query;
            }

            return $this->applyDirectives($criteria, $directives, $query, $handler, $defaultAlias);
        }

        // Resolve the requested directives in request order, carrying each key so a
        // non-root alias can route it (the pivot path); the alias is the criteria's
        // map, defaulting to the root for every related/non-pivot key. A sort family
        // resolves to a sort keyed by the full requested key, so the key routes as is.
        $directives = \array_map(
            static fn(SortDirective $directive): array => [$directive->sort->key(), $directive],
            SortMatcher::directives($requested, $criteria->sorts, $criteria->request),
        );

        return $this->applyDirectives($criteria, $directives, $query, $handler, $defaultAlias);
    }

    /**
     * Applies resolved directives, each paired with its key, most significant first.
     *
     * @template TQuery
     *
     * @param list<array{0: string, 1: SortDirective}>   $directives
     * @param TQuery                                     $query
     * @param SortHandlerInterface<contravariant TQuery> $handler
     *
     * @return TQuery
     */
    private function applyDirectives(CollectionCriteria $criteria, array $directives, mixed $query, SortHandlerInterface $handler, ?string $defaultAlias): mixed
    {
        // Empty aliasOf and no caller-supplied default alias — every non-pivot fetch
        // path and the whole in-memory provider: one composite call, most significant
        // directive first. Sorts do not compose commutatively, so the handler owns the
        // combination; the in-memory handler does its one stable
        // multi-key sort here.
        if ($criteria->aliasOf === [] && $defaultAlias === null) {
            return $handler->apply(\array_map(static fn(array $pair): SortDirective => $pair[1], $directives), $query);
        }

        // Non-empty aliasOf — the Doctrine pivot path: apply the directives ONE AT A
        // TIME in request order, each on its resolved alias, so a pivot-first `?sort`
        // keeps the request-first directive as the most significant key across both
        // aliases (the cross-alias ORDER BY the hand-rolled pivot applier built).
        if (!$handler instanceof AliasAwareSortHandler) {
            throw new \LogicException(\sprintf(
                'A sort is routed to a non-root alias, but %s is not alias-aware.',
                $handler::class,
            ));
        }

        foreach ($directives as [$key, $directive]) {
            $alias = $criteria->aliasOf[$key] ?? $defaultAlias;
            $query = $alias === null
                ? $handler->apply([$directive], $query)
                : $handler->applyOn([$directive], $query, $alias);
        }

        return $query;
    }

    /**
     * The default order's directives, validated against the declared sort vocabulary
     * exactly as a requested sort is: each default must name a declared sort (else
     * {@see SortParamUnrecognized}, a server-config error).
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
            if (SortMatcher::match($criteria->sorts, $key, $criteria->request) === null) {
                throw new SortParamUnrecognized($key);
            }
        }

        return $criteria->defaultSort;
    }
}
