<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Owns, once, the related-collection query-assembly the related endpoint
 * ({@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler::fetchRelated()})
 * and its include/linkage twin
 * ({@see RelationshipWindowBatcher}) had each duplicated: the per-relation
 * paginator chain and the resource⊕relation (⊕pivot) filter/sort vocabulary merge
 * that rides a {@see CollectionCriteria} (bundle ADR 0057).
 *
 * A stateless collaborator — it holds no state and reads everything off its
 * arguments, so a single shared instance serves every relation of every request.
 * It does not execute the criteria (the provider's
 * {@see DataProviderInterface::fetchRelatedCollection()} still owns that) and it
 * does not touch the PRIMARY-collection path, whose 2-tier paginator chain
 * (`resource -> server default`, no relation) and relation-free criteria are a
 * different shape.
 */
final class RelationCriteriaFactory
{
    /**
     * The column prefix that marks a relation filter as targeting the pivot join
     * alias: a declared filter whose `column` starts with `pivot.` (e.g.
     * `pivot.position`) is routed to the `pivot` alias with the prefix stripped
     * ({@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler}
     * strips the leading segment on that alias); any other column targets the root
     * (the related entity). Bundle ADR 0067.
     */
    private const string PIVOT_PREFIX = 'pivot.';

    /**
     * The per-relation paginator for a related to-many collection: the relation's
     * own paginator, else the related resource's, else the server default — the
     * 3-tier chain both the related endpoint and the windowing batcher resolve
     * (`relation -> related resource -> server default`). The related resource is
     * `null` for a polymorphic to-many (no single related type, so no
     * related-resource paginator); the chain then collapses to
     * `relation -> server default`.
     */
    public function paginatorFor(RelationInterface $relation, ?AbstractResource $relatedResource, Server $server): ?PaginatorInterface
    {
        return $relation->pagination()
            ?? $relatedResource?->pagination()
            ?? $server->defaultPaginator();
    }

    /**
     * Assembles the {@see CollectionCriteria} for a related to-many collection,
     * resolving the requested `filter[…]`/`sort` against the related resource's
     * vocabulary *merged* with the relation's own scoped
     * {@see RelationInterface::filters()}/{@see RelationInterface::sorts()} — extra
     * filters/sorts a relation declares for this ONE relationship endpoint (core
     * ADR 0051), never reachable on the primary `/{relatedType}` collection — plus
     * the auto-derived pivot SORT vocabulary ({@see PivotFields::sortsFor()}) when
     * `$includePivotFields`.
     *
     * Pivot FILTERS are author-declared (bundle ADR 0067): an app declares each pivot
     * filter in {@see RelationInterface::filters()} as a normal core filter whose
     * `column` is `pivot.`-prefixed. Those filters merge on every path, so the boundary
     * is preserved by `$includePivotFields`: when false (the in-memory provider and the
     * include/count Doctrine paths) every `pivot.`-columned filter is STRIPPED from the
     * merge — so a pivot key stays unrecognised in-memory (`400`) and never routes a
     * `pivot.`-column to the wrong root on the include/count paths; when true (the
     * Doctrine related endpoint) they ride through, the field value cast auto-attached
     * by the stripped column and the key routed to the `pivot` alias via `aliasOf`.
     *
     * On a key clash the relation wins over the related resource, preserving the merge
     * order `[...resourceFilters, ...relationFilters]` then keyed by `->key()`.
     * `defaultSort` is the related resource's default order (empty for a polymorphic
     * to-many); the merged vocabulary rides the criteria so both providers' existing
     * handlers apply it unchanged (ADR 0044).
     *
     * The related endpoint passes the operation's query parameters + request window
     * + `includePivotFields: $pivot`; the windowing batcher passes its synthetic
     * page-1 query parameters + page-1 window + `includePivotFields: false`
     * (includes never pivot).
     */
    public function criteriaFor(
        QueryParameters $queryParameters,
        ?AbstractResource $relatedResource,
        RelationInterface $relation,
        ?WindowInterface $window,
        bool $includePivotFields,
    ): CollectionCriteria {
        $relationFilters = $includePivotFields
            ? $this->withPivotCasts($relation)
            : $this->withoutPivotFilters($relation->filters());

        return new CollectionCriteria(
            $queryParameters,
            $this->mergeFilters($relatedResource?->filters() ?? [], $relationFilters),
            $this->mergeSorts(
                $this->mergeSorts($relatedResource?->allSorts() ?? [], $relation->sorts()),
                $includePivotFields ? PivotFields::sortsFor($relation) : [],
            ),
            $window,
            $relatedResource?->defaultSort() ?? [],
            // Route every pivot directive key to the `pivot` join alias the Doctrine
            // pivot query joins the association entity under
            // ({@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider::pivotQuery()}
            // — the literal alias 'pivot'); every related key resolves to the root.
            // Empty off the pivot path, so the alias-aware applier is inert there and
            // for the in-memory provider (bundle ADR 0059).
            $includePivotFields ? $this->pivotAliases($relation) : [],
        );
    }

    /**
     * The relation's declared filters with the value cast auto-attached to each
     * `pivot.`-columned filter that carries no explicit deserializer: strip the
     * `pivot.` prefix to the real pivot column, find the declared pivot field backing
     * that column ({@see PivotFields::fieldForColumn()}) and attach
     * `fn($v) => PivotFields::cast($v, $field)` so a typed pivot column (a `DateTime`
     * /`bool`) binds the typed value the field deserializes to. An explicit
     * `->deserializeUsing()` on the authored filter still wins (only a {@see Where}
     * exposes a deserializer to thread — `WhereIn`/`WhereNotIn` bind their split list
     * raw, and `WhereNull`/`WhereNotNull` carry no value — so the cast only attaches to
     * a {@see Where} pivot filter). A non-`pivot.` filter is returned unchanged.
     *
     * @return list<FilterInterface>
     */
    private function withPivotCasts(RelationInterface $relation): array
    {
        $filters = [];
        foreach ($relation->filters() as $filter) {
            $filters[] = $this->withPivotCast($relation, $filter);
        }

        return $filters;
    }

    /**
     * Attaches the field cast to a single `pivot.`-columned {@see Where} that carries
     * no explicit deserializer; returns every other filter unchanged.
     */
    private function withPivotCast(RelationInterface $relation, FilterInterface $filter): FilterInterface
    {
        if (!$filter instanceof Where || $filter->deserialize !== null) {
            return $filter;
        }

        $column = $this->pivotColumn($filter->column);
        if ($column === null) {
            return $filter;
        }

        $field = PivotFields::fieldForColumn($relation, $column);
        if ($field === null) {
            return $filter;
        }

        return $filter->deserializeUsing(static fn(mixed $value): mixed => PivotFields::cast($value, $field));
    }

    /**
     * Drops every filter whose column is `pivot.`-prefixed, so the in-memory provider
     * and the include/count Doctrine paths (which pass `includePivotFields: false` and
     * an empty `aliasOf`) never see a pivot-column filter: in-memory the requested
     * pivot key stays unrecognised (`400`), and the include/count paths never route a
     * `pivot.`-column to the root alias (a `root.pivot.position` path is not a valid
     * Doctrine field path). Bundle ADR 0067.
     *
     * @param list<FilterInterface> $filters
     *
     * @return list<FilterInterface>
     */
    private function withoutPivotFilters(array $filters): array
    {
        return \array_values(\array_filter(
            $filters,
            fn(FilterInterface $filter): bool => $this->pivotColumn($this->columnOf($filter)) === null,
        ));
    }

    /**
     * The pivot column a filter targets — its `pivot.`-stripped column — or null when
     * the filter does not target the pivot alias (no `pivot.` prefix). Only the leading
     * `pivot.` segment is stripped, so an embeddable pivot column (`pivot.meta.x`)
     * yields `meta.x`.
     */
    private function pivotColumn(string $column): ?string
    {
        return \str_starts_with($column, self::PIVOT_PREFIX)
            ? \substr($column, \strlen(self::PIVOT_PREFIX))
            : null;
    }

    /**
     * The declared `column` of a value-carrying scalar filter (the only filter kinds a
     * pivot column targets — {@see Where} + operators, {@see WhereIn}/{@see WhereNotIn},
     * {@see WhereNull}/{@see WhereNotNull}). A filter with no column (a
     * relationship-existence `WhereHas`/`WhereDoesntHave`, or any custom filter) cannot
     * target a pivot column, so it is reported as the empty string — never `pivot.`,
     * so it is never stripped.
     */
    private function columnOf(FilterInterface $filter): string
    {
        return match (true) {
            $filter instanceof Where,
            $filter instanceof WhereIn,
            $filter instanceof WhereNotIn,
            $filter instanceof WhereNull,
            $filter instanceof WhereNotNull => $filter->column,
            default => '',
        };
    }

    /**
     * Maps each pivot filter/sort key to the `pivot` join alias the Doctrine pivot
     * query uses, so the alias-aware {@see CriteriaApplier} applies a pivot directive
     * on the association-entity join and a related directive on the root. The string
     * 'pivot' MUST equal the join alias
     * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider::pivotQuery()}
     * inner-joins the association entity under.
     *
     * Filter aliases derive from the relation's own author-declared `pivot.`-columned
     * filters — a filter whose column is `pivot.`-prefixed routes BY ITS KEY to the
     * pivot alias (the key is independent of the column, so the routing keys on the
     * wire key while the column-strip + cast key on the column). Sort aliases still
     * derive from the auto-derived pivot sort vocabulary ({@see PivotFields::sortsFor()},
     * keyed by the pivot field name): pivot sorts remain zero-config (bundle ADR 0067).
     *
     * @return array<string, string>
     */
    private function pivotAliases(RelationInterface $relation): array
    {
        $aliasOf = [];
        foreach ($relation->filters() as $filter) {
            if ($this->pivotColumn($this->columnOf($filter)) !== null) {
                $aliasOf[$filter->key()] = 'pivot';
            }
        }
        foreach (PivotFields::sortsFor($relation) as $sort) {
            $aliasOf[$sort->key()] = 'pivot';
        }

        return $aliasOf;
    }

    /**
     * Merges two filter vocabularies, keyed by {@see FilterInterface::key()} so a
     * clash resolves to the later list's declaration (the more specific scope wins,
     * core ADR 0051). The order `[...$resourceFilters, ...$relationFilters]` is
     * preserved before the dedup; returned as a list for the {@see CollectionCriteria}.
     *
     * @param list<FilterInterface> $resourceFilters
     * @param list<FilterInterface> $relationFilters
     *
     * @return list<FilterInterface>
     */
    private function mergeFilters(array $resourceFilters, array $relationFilters): array
    {
        $merged = [];
        foreach ([...$resourceFilters, ...$relationFilters] as $filter) {
            $merged[$filter->key()] = $filter;
        }

        return \array_values($merged);
    }

    /**
     * Merges two sort vocabularies, keyed by {@see SortInterface::key()} so a clash
     * resolves to the later list's declaration (core ADR 0051). The order
     * `[...$resourceSorts, ...$relationSorts]` is preserved before the dedup;
     * returned as a list for the {@see CollectionCriteria}.
     *
     * @param list<SortInterface> $resourceSorts
     * @param list<SortInterface> $relationSorts
     *
     * @return list<SortInterface>
     */
    private function mergeSorts(array $resourceSorts, array $relationSorts): array
    {
        $merged = [];
        foreach ([...$resourceSorts, ...$relationSorts] as $sort) {
            $merged[$sort->key()] = $sort;
        }

        return \array_values($merged);
    }
}
