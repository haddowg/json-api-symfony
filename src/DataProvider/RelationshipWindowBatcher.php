<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\RelatedQuery;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\WindowedRelationshipPagination;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Windows every rendered to-many relation of a fetched page of parents to PAGE 1
 * of the set the Relationship Queries profile's per-relationship sort/filter
 * orders, under the negotiated profile (bundle ADR 0053). It is the include/linkage
 * twin of the related-collection ENDPOINT: where the endpoint reads the request's
 * plain `?sort`/`?filter`, this reads the `relatedQuery[<path>][sort|filter]` the
 * profile carries from the primary request, addressing the relationship by its
 * include path (its relation name at the top level).
 *
 * For each parent and each rendered to-many relation it:
 *  - reads the relation's {@see RelatedQuery} (its sort + filter) from the request;
 *  - resolves the page-1 paginator (relation -> related resource -> server default);
 *  - applies the relatedQuery sort/filter — over the SAME merged vocabulary the
 *    endpoint uses (the related resource's filters/sorts merged with the relation's
 *    own scoped {@see RelationInterface::filters()}/{@see RelationInterface::sorts()},
 *    core ADR 0051) — through the provider's existing
 *    {@see DataProviderInterface::fetchRelatedCollection()} seam (so an unknown
 *    sort/filter key is the endpoint's same `400`, and the scoping/count-free vs
 *    countable distinction is reused verbatim);
 *  - writes the page-1 items back onto the parent's relation property, so the
 *    linkage `data` the serializer reads off the parent IS page 1 of the
 *    profile-ordered set (the `sort` selects which members land on page 1 — page is
 *    out for includes);
 *  - builds a {@see RelationshipPagination} carrying the page + the plain-form
 *    (`sort=…&filter[…]=…`) query string, so core emits the relationship object's
 *    `first`/`prev`/`next` (+`last` when countable) links against the
 *    relationship-linkage endpoint in the spec's plain form, never the profile's
 *    `relatedQuery[…]` form.
 *
 * The page-1 windowing is per parent (each relation is scoped to its own parent),
 * so a collection include is N relations x M parents fetches — the portable path,
 * reusing the proven per-parent scoped query of every provider (a native windowed
 * batch is a future optimization, bundle ADR 0053). A to-one relation, a relation
 * with neither a paginator nor any relatedQuery, and any relation core does not
 * render are left untouched (today's full-collection behaviour).
 *
 * The result is keyed by the parent's object identity then relation name, mirroring
 * {@see RelationCountBatcher} / {@see WindowedRelationshipPagination}, so the render
 * seam resolves a page back to the very object the serializer holds. Returns `null`
 * when the profile was not negotiated, so the handler clears the seam and renders
 * exactly as before the profile existed.
 */
final class RelationshipWindowBatcher
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
    ) {}

    /**
     * Builds the per-page relationship-window seam for `$type`'s rendered to-many
     * relations, or `null` when the request did not negotiate the Relationship
     * Queries profile (so the handler skips the injection and renders unchanged).
     *
     * @param list<object> $parents the already-fetched page of parents
     */
    public function batch(Server $server, string $type, array $parents, JsonApiRequestInterface $request): ?WindowedRelationshipPagination
    {
        if (!$request->isProfileRequested(RelationshipQueriesProfile::URI)) {
            return null;
        }

        $relations = $this->windowableRelations($server, $type);

        // Up-front path validation (core ADR 0058 delegates this to the host): every
        // relatedQuery/rQ path the client addressed MUST resolve to a windowable
        // (monomorphic to-many) relation of the primary type. An unknown path
        // (a typo, a relation of an included resource via an unhandled dotted path) or
        // a to-one path used for this list op is the related-collection endpoint's
        // same `400`, with `source.parameter` the offending profile param — never a
        // silent no-op. Runs before the `$parents === []` short-circuit so an empty
        // page still rejects a bad path.
        $this->validatePaths($relations, $request);

        if ($parents === []) {
            return null;
        }

        if ($relations === []) {
            return null;
        }

        $pages = [];
        foreach ($parents as $parent) {
            // Snapshot each relation's backing column ONCE before any windowing, so a
            // relation reads the parent's ORIGINAL related set rather than a value a
            // sibling relation already trimmed in place. Two relations may share one
            // column (e.g. a default `comments` and a paginated `pagedComments` over
            // the same association); each then windows from the same original set, and
            // the write-back of the windowed page is last-writer-wins on that shared
            // column — distinct-column relations never collide.
            $snapshot = $this->snapshotColumns($parent, $relations);

            foreach ($relations as $relation) {
                $column = $relation->column() ?? $relation->name();
                $page = $this->windowRelation($server, $parent, $relation, $request, $snapshot[$column] ?? null);
                if ($page !== null) {
                    $pages[\spl_object_id($parent)][$relation->name()] = $page;
                }
            }
        }

        return $pages === [] ? null : new WindowedRelationshipPagination($pages);
    }

    /**
     * The to-many relations of `$type` that may be windowed under the profile: a
     * monomorphic to-many that either carries a paginator (relation/related/server
     * default) or is addressed by a `relatedQuery`/`rQ` param. A polymorphic
     * to-many (members span types — no single related provider or shared vocabulary)
     * and a to-one are excluded; the related/relationship endpoints still serve them
     * directly.
     *
     * @return list<RelationInterface>
     */
    private function windowableRelations(Server $server, string $type): array
    {
        $relations = [];
        foreach ($this->types->relationsFor($server, $type) as $relation) {
            if (!$relation->isToMany()) {
                continue;
            }

            // Polymorphic to-many: members span entity classes, so there is no
            // single related provider to window through — leave it to its endpoint.
            if (\count($relation->relatedTypes()) > 1) {
                continue;
            }

            $relations[] = $relation;
        }

        return $relations;
    }

    /**
     * Rejects any addressed `relatedQuery`/`rQ` path that is not a windowable
     * (monomorphic to-many) relation of the primary type — an unknown relationship
     * path (including a dotted path the batcher does not address, which only
     * windows top-level relations) or a to-one path used for this list op — with the
     * related-collection endpoint's same `400`. `source.parameter` points at the
     * canonical profile form (`relatedQuery[<path>]`); the unknown sort/filter KEY on
     * a valid to-many path is still validated downstream by the fetch (core ADR 0058
     * delegates path/cardinality validation to the host).
     *
     * @param list<RelationInterface> $relations the windowable to-many relations of the type
     */
    private function validatePaths(array $relations, JsonApiRequestInterface $request): void
    {
        $windowable = [];
        foreach ($relations as $relation) {
            $windowable[$relation->name()] = true;
        }

        foreach ($request->getRelatedQueryPaths() as $path) {
            if (!isset($windowable[$path])) {
                throw new QueryParamUnrecognized(RelationshipQueriesProfile::FAMILY . '[' . $path . ']');
            }
        }
    }

    /**
     * Snapshots each windowable relation's backing column value on `$parent` once,
     * keyed by column, so a relation can window from the original related set even
     * after a sibling relation sharing the column trimmed it in place. A column read
     * once is reused for every relation that shares it.
     *
     * @param list<RelationInterface> $relations
     *
     * @return array<string, mixed> `column => original value`
     */
    private function snapshotColumns(object $parent, array $relations): array
    {
        $snapshot = [];
        foreach ($relations as $relation) {
            $column = $relation->column() ?? $relation->name();
            if (!\array_key_exists($column, $snapshot)) {
                $snapshot[$column] = Accessor::get($parent, $column);
            }
        }

        return $snapshot;
    }

    /**
     * Windows one relation on one parent to page 1, writing the windowed linkage
     * back onto the parent and returning the {@see RelationshipPagination} — or
     * `null` when the relation is not paginated for this request (no relatedQuery
     * and no effective paginator), in which case the parent's relation is left as
     * its full set and core emits no relationship-object pagination links for it.
     *
     * `$snapshot` is the relation column's value as it was BEFORE any sibling
     * relation windowed it in place; it is restored onto the parent before the
     * fetch so the in-memory provider (which reads the related set off the property)
     * windows from the original set, not a sibling's already-trimmed page. The
     * Doctrine provider scopes its own query and ignores the property, so the
     * restore is inert there.
     */
    private function windowRelation(Server $server, object $parent, RelationInterface $relation, JsonApiRequestInterface $request, mixed $snapshot): ?RelationshipPagination
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$this->providers->supportsType($relatedType)) {
            return null;
        }

        $relatedQuery = $request->getRelatedQuery($relation->name());

        $relatedResource = $this->types->resourceFor($server, $relatedType);
        $paginator = $relation->pagination()
            ?? $relatedResource?->pagination()
            ?? $server->defaultPaginator();

        // No paginator AND no relatedQuery: the relationship is neither sliced nor
        // re-ordered/filtered from the primary request, so it renders as its full
        // set exactly as before the profile (and core emits no pagination links).
        if ($paginator === null && $relatedQuery->isEmpty()) {
            return null;
        }

        // Restore the original related set onto the column so the fetch windows from
        // it rather than a sibling relation's already-windowed page (see batch()).
        Accessor::set($parent, $relation->column() ?? $relation->name(), $snapshot);

        // The page-1 synthetic request: page is OUT for includes, so it is fixed at
        // page 1, and the relatedQuery sort/filter ride as the plain `?sort`/`?filter`
        // the provider's existing fetchRelatedCollection consumes. page[size] is left
        // to the paginator's default so the relation default size applies.
        $pageRequest = $this->page1Request($request, $relatedQuery);

        $window = $paginator?->window($pageRequest);

        $criteria = new CollectionCriteria(
            new QueryParameters(
                fields: [],
                includes: [],
                sort: $relatedQuery->sortFields(),
                filter: $relatedQuery->filter,
                pagination: $pageRequest->getPagination(),
            ),
            $this->mergeFilters($relatedResource?->filters() ?? [], $relation->filters()),
            $this->mergeSorts($relatedResource?->allSorts() ?? [], $relation->sorts()),
            $window,
            $relatedResource?->defaultSort() ?? [],
        );

        // Reuse the endpoint fetch: per-parent scoping, merged-vocabulary key
        // validation (unknown key -> the endpoint's 400), and the count-free vs
        // countable page distinction are all reused verbatim (bundle ADR 0053).
        $result = $this->providers->forType($relatedType)
            ->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $pageRequest);

        $items = \is_array($result->items) ? \array_values($result->items) : \iterator_to_array($result->items, false);

        // Write page 1 back onto the parent so the rendered linkage IS this page —
        // the `sort` selects which members land on page 1 (page is out for includes).
        // The page is wrapped to match the column's container (a Doctrine `Collection`
        // property cannot take a raw array), and a relation whose value is not a
        // writable property (a computed extractUsing value) is left as-is; the
        // pagination links still describe the page.
        Accessor::set($parent, $relation->column() ?? $relation->name(), $this->asContainer($items, $snapshot));

        if ($paginator === null) {
            // relatedQuery present but no paginator: the relationship is re-ordered/
            // filtered in place but not sliced, so there is no page to navigate and no
            // pagination links to emit.
            return null;
        }

        return $this->paginationFor($paginator, $pageRequest, $relation, $items, $result, $relatedQuery);
    }

    /**
     * Builds the {@see RelationshipPagination} for the windowed page: a countable
     * relation counts the total and emits `first`/`prev`/`next`/`last`; a
     * non-countable one paginates count-free (no total, no `last`; `next` driven by
     * the `hasMore` probe), reusing the slice-1 count-free vs countable distinction
     * (bundle ADR 0052/0053). The plain-form query string carries the relatedQuery
     * sort/filter so the links mirror them.
     *
     * @param CollectionResult<object> $result
     * @param list<object>             $items
     */
    private function paginationFor(
        PaginatorInterface $paginator,
        JsonApiRequestInterface $pageRequest,
        RelationInterface $relation,
        array $items,
        CollectionResult $result,
        RelatedQuery $relatedQuery,
    ): RelationshipPagination {
        $queryString = $relatedQuery->toPlainQueryString();

        if ($relation->isCountable() && $result->total !== null) {
            return new RelationshipPagination($paginator->paginate($pageRequest, $items, $result->total), $queryString);
        }

        return new RelationshipPagination(
            $paginator->paginateWithoutCount($pageRequest, $items, $result->hasMore),
            $queryString,
        );
    }

    /**
     * A page-1 request the paginator/provider read the window and plain `?sort` /
     * `?filter` from: the relatedQuery sort/filter projected onto the plain query
     * params, with `page[number]` pinned to 1 (page is OUT for includes) and
     * `page[size]` left to the paginator default so the relation default size
     * applies.
     */
    private function page1Request(JsonApiRequestInterface $request, RelatedQuery $relatedQuery): JsonApiRequestInterface
    {
        return $request
            ->withQueryParam('sort', \implode(',', $relatedQuery->sortFields()))
            ->withQueryParam('filter', $relatedQuery->filter)
            ->withQueryParam('page', ['number' => '1']);
    }

    /**
     * Wraps the windowed page in the container the relation's column expects: a
     * Doctrine `Collection`-typed property cannot take a raw array, so when the
     * column held a {@see \Doctrine\Common\Collections\Collection} the page is
     * wrapped in an {@see \Doctrine\Common\Collections\ArrayCollection}; an array
     * (the in-memory model) keeps the plain list. The snapshot is the column's
     * pre-window value, so it carries the container type to preserve.
     *
     * @param list<object> $items the page-1 items
     *
     * @return list<object>|\Doctrine\Common\Collections\Collection<int, object>
     */
    private function asContainer(array $items, mixed $snapshot): array|object
    {
        if (
            $snapshot instanceof \Doctrine\Common\Collections\Collection
            && \class_exists(\Doctrine\Common\Collections\ArrayCollection::class)
        ) {
            return new \Doctrine\Common\Collections\ArrayCollection($items);
        }

        return $items;
    }

    /**
     * Merges the related resource's filter vocabulary with the relation's own
     * scoped filters, keyed by {@see FilterInterface::key()} so a clash resolves to
     * the relation's declaration (the more specific scope wins, core ADR 0051) —
     * the same merge the related-collection endpoint performs in the handler.
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
     * Merges the related resource's sort vocabulary with the relation's own scoped
     * sorts, keyed by {@see SortInterface::key()} so a clash resolves to the
     * relation's declaration (core ADR 0051) — the same merge the endpoint performs.
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
