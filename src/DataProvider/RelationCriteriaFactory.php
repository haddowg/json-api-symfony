<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DefaultSortedRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterFamilyInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use haddowg\JsonApi\Server\Server;

/**
 * Owns, once, the related-collection query-assembly the related endpoint
 * ({@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler::fetchRelated()})
 * and its include/linkage twin
 * ({@see RelationshipWindowBatcher}) had each duplicated: the per-relation
 * paginator chain and the resource⊕relation (⊕pivot) filter/sort vocabulary merge
 * that rides a {@see CollectionCriteria}.
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
     * (the related entity).
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
     *
     * Each level's `pagination()` is the single source of truth and
     * receives the already-resolved fallback (the level below it), so a `null`
     * return now means *no pagination* rather than "inherit the default". The
     * fallback is composed bottom-up: the related resource resolves against the
     * server default (`$relatedResource->pagination($serverDefault)`), and that
     * resolved value is fed into `$relation->pagination($fallback)` — where the
     * relation's explicit `withoutPagination()` returns `null` regardless of the
     * fallback (the opt-out short-circuits before the `?? $fallback`, core C6). A
     * polymorphic to-many has no related resource, so the relation's fallback is
     * the server default directly.
     */
    public function paginatorFor(RelationInterface $relation, ?AbstractResource $relatedResource, Server $server): ?PaginatorInterface
    {
        return self::resolvePaginator($relation, $relatedResource, $server->defaultPaginator());
    }

    /**
     * The chain {@see paginatorFor()} resolves, against an already-resolved server
     * default. The OpenAPI metadata projects related-collection page schemas through
     * it so the document states the paginator the endpoint serves.
     */
    public static function resolvePaginator(RelationInterface $relation, ?AbstractResource $relatedResource, ?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        $fallback = $relatedResource?->pagination($serverDefault) ?? $serverDefault;

        return $relation->pagination($fallback);
    }

    /**
     * Assembles the {@see CollectionCriteria} for a related to-many collection,
     * resolving the requested `filter[…]`/`sort` against the related resource's
     * vocabulary *merged* with the relation's own scoped
     * {@see RelationInterface::filters()}/{@see RelationInterface::sorts()} — extra
     * filters/sorts a relation declares for this ONE relationship endpoint (core
     * docs/relations.md), never reachable on the primary `/{relatedType}` collection — plus
     * the auto-derived pivot SORT vocabulary ({@see PivotFields::sortsFor()}) when
     * `$includePivotFields`.
     *
     * Pivot FILTERS are author-declared: an app declares each pivot
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
     * order `[...resourceFilters, ...relationFilters]`. Filters and sorts merge through
     * core's {@see FilterMatcher::merge()} and {@see SortMatcher::merge()}, which keep a
     * family beside an exact filter or sort sharing its key.
     * `defaultSort` is the relation's default read order ({@see defaultOrder()}); the
     * merged vocabulary rides the criteria so both providers' existing handlers apply
     * it unchanged.
     *
     * The related endpoint passes the operation's query parameters + request window
     * + `includePivotFields: $pivot`; the windowing batcher passes its synthetic
     * page-1 query parameters + page-1 window + `includePivotFields: false`
     * (includes never pivot).
     *
     * `$wantsCount` rides onto the criteria: only the related endpoint passes
     * `true` (the relation's paginator opted in via `withCount()`, or the client
     * asked `?withCount=_self_` under a countable() relation); the include/linkage/
     * count batchers leave the default `false`, so those paths stay count-free (their
     * relationship-object totals come from the separate RelationCountInterface seam).
     *
     * `$request` rides onto the criteria too, so a sort or filter family in the
     * merged vocabulary can resolve a requested key.
     *
     * `$pivotSorts` adds the pivot sorts, routed to the `pivot` alias, without the
     * pivot filters: a path that reads a pivot relation through the provider's pivot
     * query but takes no pivot filter (the Relationship Queries window) still sorts by
     * a pivot field, its default order included.
     */
    public function criteriaFor(
        QueryParameters $queryParameters,
        ?AbstractResource $relatedResource,
        RelationInterface $relation,
        ?WindowInterface $window,
        bool $includePivotFields,
        bool $wantsCount = false,
        ?JsonApiRequestInterface $request = null,
        bool $pivotSorts = false,
    ): CollectionCriteria {
        $relationFilters = $includePivotFields
            ? $this->withPivotCasts($relation)
            : $this->withoutPivotFilters($relation->allFilters());
        $filters = $this->mergeFilters($relatedResource?->allFilters() ?? [], $relationFilters);

        return new CollectionCriteria(
            $queryParameters,
            $filters,
            SortMatcher::merge(
                $relatedResource?->allSorts() ?? [],
                $relation->sorts(),
                $includePivotFields || $pivotSorts ? PivotFields::sortsFor($relation) : [],
            ),
            $window,
            $this->defaultOrder($relation, $relatedResource, $includePivotFields || $pivotSorts),
            // Route every pivot directive key to the `pivot` join alias the Doctrine
            // pivot query joins the association entity under
            // ({@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider::pivotQuery()}
            // — the literal alias 'pivot'); every related key resolves to the root.
            // Empty off the pivot path, so the alias-aware applier is inert there and
            // for the in-memory provider.
            match (true) {
                $includePivotFields => $this->pivotAliases($relation, $filters, $queryParameters, $request),
                $pivotSorts => $this->pivotSortAliases($relation),
                default => [],
            },
            // Whether the provider should run the COUNT for this related fetch:
            // the related endpoint passes `true` only when the relation's own paginator
            // opted in via withCount() OR the client asked `?withCount=_self_` under a
            // countable() relation (the handler 400s an un-countable `_self_` first).
            // The include/linkage/count batchers leave it `false` — those paths stay
            // count-free (their relationship-object totals ride the separate
            // RelationCountInterface seam).
            wantsCount: $wantsCount,
            request: $request,
        );
    }

    /**
     * The criteria an include or linkage read of a to-many runs when nothing is
     * requested for it: no filter and no window, only the relation's default read
     * order and the sort vocabulary it is checked against, so the members render in
     * the order the relation's endpoints serve them. `$pivotSorts` is whether the
     * provider reads the relation through its pivot query.
     */
    public function orderFor(?AbstractResource $relatedResource, RelationInterface $relation, JsonApiRequestInterface $request, bool $pivotSorts = false): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: $request->getPagination()),
            sorts: SortMatcher::merge($relatedResource?->allSorts() ?? [], $relation->sorts(), $pivotSorts ? PivotFields::sortsFor($relation) : []),
            defaultSort: $this->defaultOrder($relation, $relatedResource, $pivotSorts),
            aliasOf: $pivotSorts ? $this->pivotSortAliases($relation) : [],
            request: $request,
        );
    }

    /**
     * Whether a to-many renders its members in a declared order: its own default, or
     * the related type's. An unordered relation keeps the order its storage returns.
     */
    public function isOrdered(?AbstractResource $relatedResource, RelationInterface $relation): bool
    {
        return $relation->isToMany() && $this->defaultOrder($relation, $relatedResource, false) !== [];
    }

    /**
     * The order a relation's members are read in when no sort is requested: the
     * relation's own default, else the related type's, as core's
     * {@see \haddowg\JsonApi\Resource\Sort\RelationSorts::defaultFor()} resolves it.
     * On the pivot path a directive naming a pivot field takes the pivot sort, so it
     * orders by the field's stored column on the pivot alias.
     *
     * @return list<SortDirective>
     */
    private function defaultOrder(RelationInterface $relation, ?AbstractResource $relatedResource, bool $pivot): array
    {
        $own = $relation instanceof DefaultSortedRelationInterface ? $relation->defaultSort() : [];
        if ($own === [] || !$relation->isToMany()) {
            return $relatedResource?->defaultSort() ?? [];
        }

        if (!$pivot) {
            return $own;
        }

        $pivotSorts = [];
        foreach (PivotFields::sortsFor($relation) as $sort) {
            $pivotSorts[$sort->key()] = $sort;
        }

        return \array_map(
            static fn(SortDirective $directive): SortDirective => isset($pivotSorts[$directive->sort->key()])
                ? new SortDirective($pivotSorts[$directive->sort->key()], $directive->descending)
                : $directive,
            $own,
        );
    }

    /**
     * Each pivot sort key routed to the `pivot` join alias.
     *
     * @return array<string, string>
     */
    private function pivotSortAliases(RelationInterface $relation): array
    {
        $aliasOf = [];
        foreach (PivotFields::sortsFor($relation) as $sort) {
            $aliasOf[$sort->key()] = 'pivot';
        }

        return $aliasOf;
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
     * a {@see Where} pivot filter). A non-`pivot.` filter is returned unchanged, and a
     * filter family gets the same cast on each filter it resolves.
     *
     * @return list<FilterInterface>
     */
    private function withPivotCasts(RelationInterface $relation): array
    {
        $filters = [];
        foreach ($relation->allFilters() as $filter) {
            $filters[] = $filter instanceof FilterFamilyInterface
                ? new PivotTreatedFilterFamily($filter, fn(FilterInterface $resolved): FilterInterface => $this->withPivotCast($relation, $resolved))
                : $this->withPivotCast($relation, $filter);
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

        // The value deserializer lives on the WhereBuilder; the built Where VO is
        // readonly, so thread every property through a fresh VO with the pivot cast
        // attached (identical to the old copy-on-write `deserializeUsing()` wither).
        return new Where(
            $filter->key,
            $filter->column,
            $filter->operator,
            static fn(mixed $value): mixed => PivotFields::cast($value, $field),
            $filter->singular,
            $filter->default,
            $filter->hasDefault,
            $filter->constraints,
            $filter->description,
            $filter->hasExample,
            $filter->example,
            $filter->fixedValue,
            $filter->hasFixed,
        );
    }

    /**
     * Drops every filter whose column is `pivot.`-prefixed, so the in-memory provider
     * and the include/count Doctrine paths (which pass `includePivotFields: false` and
     * an empty `aliasOf`) never see a pivot-column filter: in-memory the requested
     * pivot key stays unrecognised (`400`), and the include/count paths never route a
     * `pivot.`-column to the root alias (a `root.pivot.position` path is not a valid
     * Doctrine field path). A filter family stays, but a filter it resolves to a
     * `pivot.` column is dropped the same way, so its key is unrecognised too.
     *
     * @param list<FilterInterface> $filters
     *
     * @return list<FilterInterface>
     */
    private function withoutPivotFilters(array $filters): array
    {
        $kept = [];
        foreach ($filters as $filter) {
            if ($filter instanceof FilterFamilyInterface) {
                $kept[] = new PivotTreatedFilterFamily($filter, fn(FilterInterface $resolved): ?FilterInterface => $this->pivotColumn($this->columnOf($resolved)) === null ? $resolved : null);
            } elseif ($this->pivotColumn($this->columnOf($filter)) === null) {
                $kept[] = $filter;
            }
        }

        return $kept;
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
     * keyed by the pivot field name): pivot sorts remain zero-config.
     *
     * A filter family's keys are only known once requested, so each requested key a
     * relation family resolves to a `pivot.`-columned filter routes to the pivot alias
     * as well. An exact filter of the merged vocabulary always wins the key, as it does
     * in the applier.
     *
     * @param list<FilterInterface> $filters the merged filter vocabulary of the criteria
     *
     * @return array<string, string>
     */
    private function pivotAliases(RelationInterface $relation, array $filters, QueryParameters $queryParameters, ?JsonApiRequestInterface $request): array
    {
        $aliasOf = [];
        foreach ($relation->allFilters() as $filter) {
            if (!$filter instanceof FilterFamilyInterface && $this->pivotColumn($this->columnOf($filter)) !== null) {
                $aliasOf[$filter->key()] = 'pivot';
            }
        }
        if ($request !== null) {
            foreach (\array_keys($queryParameters->filter) as $key) {
                $resolved = FilterMatcher::match($filters, (string) $key, $request);
                if ($resolved !== null && !\in_array($resolved, $filters, true) && $this->pivotColumn($this->columnOf($resolved)) !== null) {
                    $aliasOf[(string) $key] = 'pivot';
                }
            }
        }
        return [...$aliasOf, ...$this->pivotSortAliases($relation)];
    }

    /**
     * Merges two filter vocabularies through core's {@see FilterMatcher::merge()}, so a
     * clash resolves to the later list's declaration (the more specific scope wins,
     * core docs/relations.md) while a filter family stays beside an exact filter
     * sharing its key. The order `[...$resourceFilters, ...$relationFilters]` is
     * preserved before the dedup.
     *
     * @param list<FilterInterface> $resourceFilters
     * @param list<FilterInterface> $relationFilters
     *
     * @return list<FilterInterface>
     */
    private function mergeFilters(array $resourceFilters, array $relationFilters): array
    {
        return FilterMatcher::merge($resourceFilters, $relationFilters);
    }
}
