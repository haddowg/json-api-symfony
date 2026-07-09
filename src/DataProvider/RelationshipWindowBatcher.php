<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\RelatedQuery;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Serializer\WindowedRelationshipLinkage;
use haddowg\JsonApiBundle\Serializer\WindowedRelationshipPagination;
use haddowg\JsonApiBundle\Serializer\WindowedRelationshipResult;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Windows every to-many relation whose linkage DATA renders for the request — a
 * relation included at the primary level (default-includes honoured) or one that
 * renders data unconditionally (`withData()`) — of a fetched page of parents to
 * PAGE 1 of the set the Relationship Queries profile's per-relationship sort/filter
 * orders, under the negotiated profile (bundle ADR 0053). A lazy, links-only,
 * not-included to-many is left untouched: windowing it would waste a fetch and leak
 * the filtered linkage the lazy default omits (bundle ADR 0086 — see
 * {@see windowableRelations()}). It is the include/linkage twin of the
 * related-collection ENDPOINT: where the endpoint reads the request's plain
 * `?sort`/`?filter`, this reads the `relatedQuery[<path>][sort|filter]` the profile
 * carries from the primary request, addressing the relationship by its include path
 * (its relation name at the top level).
 *
 * For each rendered to-many relation it, ONCE over the whole page:
 *  - reads the relation's {@see RelatedQuery} (its sort + filter) from the request;
 *  - resolves the page-1 paginator (relation -> related resource -> server default)
 *    and assembles the page-1 criteria — both independent of the parent;
 *  - applies the relatedQuery sort/filter — over the SAME merged vocabulary the
 *    endpoint uses (the related resource's filters/sorts merged with the relation's
 *    own scoped {@see RelationInterface::filters()}/{@see RelationInterface::sorts()},
 *    core ADR 0051) — through the provider's batched
 *    {@see DataProviderInterface::fetchRelatedCollectionBatch()} seam (so an unknown
 *    sort/filter key is the endpoint's same `400`, and the scoping/count-free vs
 *    countable distinction is reused verbatim) for the WHOLE page in one round-trip;
 *  - per parent, records the page-1 items as a {@see RelationshipLinkage} supplied to
 *    core OUT-OF-BAND (the relationship-linkage seam, core ADR 0083) — NOT written onto
 *    the parent's relation property — so the linkage `data` IS page 1 of the
 *    profile-ordered set (the `sort` selects which members land on page 1 — page is out
 *    for includes) WHILE any relation sharing the backing column still renders its own
 *    membership (bundle ADR 0086 — a destructive property write would leak the filtered
 *    page onto a column-sharing bystander, and on Doctrine flip its load-state);
 *  - per parent, builds a {@see RelationshipPagination} carrying the page + the
 *    plain-form (`sort=…&filter[…]=…`) query string, so core emits the relationship
 *    object's `first`/`prev`/`next` (+`last` when countable) links against the
 *    relationship-linkage endpoint in the spec's plain form, never the profile's
 *    `relatedQuery[…]` form.
 *
 * The page-1 windowing is BATCHED: ONE
 * {@see DataProviderInterface::fetchRelatedCollectionBatch()} per windowed relation
 * scopes the whole page of parents, so a collection include of N relations over M
 * parents is O(N) provider round-trips, NOT O(M*N) — the per-parent fetch loop is
 * retired (bundle ADR 0061, replacing the per-parent path of bundle ADR 0053). A
 * to-one relation, a relation with neither a paginator nor any relatedQuery, and any
 * relation core does not render are left untouched (today's full-collection
 * behaviour).
 *
 * The result pairs a {@see WindowedRelationshipPagination} and a
 * {@see WindowedRelationshipLinkage} in a {@see WindowedRelationshipResult}, each keyed
 * by the parent's object identity then relation name, so the render seams resolve a page
 * back to the very object the serializer holds. Returns `null` when the profile was not
 * negotiated, so the handler clears the seams and renders exactly as before the profile
 * existed.
 */
final class RelationshipWindowBatcher
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
        private readonly RelationCriteriaFactory $relationCriteria,
        // The same optional filter-value validator the CrudOperationHandler carries
        // (wired only when `symfony/validator` is present): the profile/include path
        // validates a relatedQuery filter VALUE against the per-relation merged
        // vocabulary at the two points criteria is assembled — to-one nulling and the
        // to-many window — so a mistyped relatedQuery value is the endpoint's same 400
        // here too (bundle ADR 0068 follow-up #2). Null without the validator, exactly
        // as the handler degrades; only RAW client input is validated (ADR 0048).
        private readonly ?\haddowg\JsonApiBundle\Validation\FilterValueValidator $filterValues = null,
    ) {}

    /**
     * Builds the per-page relationship-window seam for `$type`'s rendered to-many
     * relations, or `null` when the request did not negotiate the Relationship
     * Queries profile (so the handler skips the injection and renders unchanged).
     *
     * @param list<object> $parents the already-fetched page of parents
     */
    public function batch(Server $server, string $type, array $parents, JsonApiRequestInterface $request): ?WindowedRelationshipResult
    {
        if (!$request->isProfileRequested(RelationshipQueriesProfile::URI)) {
            return null;
        }

        $allRelations = $this->types->relationsFor($server, $type);

        // The monomorphic to-one relations addressed by a filter-only relatedQuery: a
        // relation filter that excludes a to-one's single target nulls the linkage (and
        // omits the include) — the to-one twin of the windowed to-many (bundle ADR 0068).
        $toOneFiltered = $this->toOneFilteredRelations($allRelations, $request);

        // Up-front path validation (core ADR 0058 delegates this to the host): every
        // relatedQuery/rQ path the client addressed MUST resolve to a windowable
        // (monomorphic to-many) relation of the primary type — or a monomorphic to-one
        // addressed with `[filter]` ONLY (bundle ADR 0068; a `[sort]`/`[page]` on a
        // to-one stays a 400, a single member has nothing to order or page). An unknown
        // path (a typo, a relation of an included resource via an unhandled dotted path)
        // is the related-collection endpoint's same `400`, with `source.parameter` the
        // offending profile param — never a silent no-op. Runs before the
        // `$parents === []` short-circuit so an empty page still rejects a bad path.
        $this->validatePaths($allRelations, $request);

        if ($parents === []) {
            return null;
        }

        // Null each filter-excluded to-one's linkage across the page in ONE batched match
        // per relation (no N+1) — runs whether or not any to-many is windowed, and after
        // preloadIncludes() at the call site so it overwrites a preloaded to-one target
        // with null (bundle ADR 0068). It produces no pagination entry (a to-one has none).
        $this->nullExcludedToOnes($server, $type, $parents, $toOneFiltered, $request);

        // The to-many relations to window — restricted to those whose linkage DATA renders
        // for THIS request (bundle ADR 0086): a relation that is included at the primary
        // level (honouring default-includes) or renders data unconditionally (`withData()`).
        // A lazy, links-only, not-included to-many is NOT windowed — windowing it would
        // both waste a fetch AND leak, because writing page 1 onto the parent property flips
        // the storage load-state predicate to "loaded" so core's shouldDeferLinkage() stops
        // deferring and renders the filtered linkage the lazy default omits. Resolved off the
        // non-empty page's first parent as the representative, mirroring RelatedIncludeBatcher.
        $relations = $this->windowableRelations($server, $type, $allRelations, $parents[0], $request);

        // A to-many that is addressed by a relatedQuery sort/filter but NOT windowed (it
        // renders links-only this request) must STILL reject an unknown sort/filter KEY
        // with the related-collection endpoint's same `400` (bundle ADR 0086): the path
        // resolves and the key vocabulary is the same whether or not the linkage renders,
        // so a typo'd key is a client error regardless. The window fetch normally surfaces
        // that `400` as a side effect — but a gated relation never fetches, so the keys are
        // validated here directly against the merged vocabulary, with NO query and NO
        // property mutation (so no leak). The windowed relations validate their keys through
        // the fetch as before, so this only covers the gated remainder.
        $this->validateUnwindowedRelatedQueryKeys($server, $type, $allRelations, $relations, $request);

        if ($relations === []) {
            return null;
        }

        // Snapshot every windowable column for every parent ONCE up front, so each
        // relation windows from the parent's ORIGINAL related set rather than a value a
        // sibling relation already trimmed in place. Two relations may share one column
        // (e.g. a default `comments` and a paginated `pagedComments` over the same
        // association); each windows from the same original set. Keyed by object id then
        // column so a relation restores its own column before its batch reads it.
        $snapshots = [];
        foreach ($parents as $parent) {
            $snapshots[\spl_object_id($parent)] = $this->snapshotColumns($parent, $relations);
        }

        // ONE batch fetch per windowed relation over the WHOLE page, replacing the
        // per-parent fetch loop (bundle ADR 0061): a collection include of N windowable
        // relations over M parents is O(N) provider round-trips, not O(M*N). For each
        // relation, restore the page's snapshot for its column (so the in-memory witness
        // reads each parent's original set), fetch the page-1 windowed batch once, then
        // record each parent's page-1 linkage + relationship-object pagination — WITHOUT
        // writing the page onto the parent property (bundle ADR 0086): the page is supplied
        // to core OUT-OF-BAND through the relationship-linkage seam, so a relation sharing
        // the column (a not-windowed lazy bystander, or another windowed relation) renders
        // its OWN membership rather than this relation's filtered page (which a destructive
        // column write would leak, and on Doctrine flip the load-state to "loaded").
        $pages = [];
        $linkages = [];
        foreach ($relations as $relation) {
            foreach ($this->windowRelationOverPage($server, $type, $parents, $relation, $request, $snapshots) as $objectId => $window) {
                if ($window['page'] !== null) {
                    $pages[$objectId][$relation->name()] = $window['page'];
                }
                $linkages[$objectId][$relation->name()] = $window['linkage'];
            }
        }

        // After the window loop every column has been restored to its pre-window snapshot
        // by windowRelationOverPage's per-relation restore (and never overwritten with a
        // filtered page), so the parents are pristine and any bystander renders its true
        // membership — the windowed relations read their pages from the linkage seam.
        return $pages === [] && $linkages === []
            ? null
            : new WindowedRelationshipResult(
                $pages === [] ? null : new WindowedRelationshipPagination($pages),
                $linkages === [] ? null : new WindowedRelationshipLinkage($linkages),
            );
    }

    /**
     * Windows ONE relation over the whole page of parents in a single
     * {@see DataProviderInterface::fetchRelatedCollectionBatch()} round-trip, then per
     * parent records the page-1 linkage ({@see RelationshipLinkage}, supplied to core
     * out-of-band so the page is NOT written onto the parent property) and its
     * {@see RelationshipPagination} — the batched replacement for the retired
     * per-parent windowing loop (bundle ADR 0061).
     *
     * Returns a `spl_object_id(parent) => ['page' => ?RelationshipPagination,
     * 'linkage' => RelationshipLinkage]` map of the parents whose relation was windowed;
     * a relation that is not paginated AND not re-ordered/filtered for this request (no
     * relatedQuery and no effective paginator) yields an empty map and renders off its
     * (untouched) property exactly as before. The criteria, paginator, and synthetic
     * page-1 request are resolved ONCE for the relation (they do not depend on the
     * parent), so only the per-parent linkage/pagination assembly runs in the loop.
     *
     * @param list<object>                $parents
     * @param array<int, array<string, mixed>> $snapshots `spl_object_id(parent) => [column => original value]`
     *
     * @return array<int, array{page: ?RelationshipPagination, linkage: RelationshipLinkage}>
     */
    private function windowRelationOverPage(
        Server $server,
        string $type,
        array $parents,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        array $snapshots,
    ): array {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$this->providers->supportsType($relatedType)) {
            return [];
        }

        $relatedQuery = $request->getRelatedQuery($relation->name());

        $relatedResource = $this->types->resourceFor($server, $relatedType);
        $paginator = $this->relationCriteria->paginatorFor($relation, $relatedResource, $server);

        // No paginator AND no relatedQuery: the relationship is neither sliced nor
        // re-ordered/filtered from the primary request, so it renders as its full set
        // exactly as before the profile (and core emits no pagination links).
        if ($paginator === null && $relatedQuery->isEmpty()) {
            return [];
        }

        $column = $relation->column() ?? $relation->name();

        // Restore each parent's ORIGINAL related set onto the column before the batch
        // reads it (the in-memory witness reads the related set off the property), so a
        // sibling relation sharing the column has not already trimmed it. The Doctrine
        // provider scopes its own query and ignores the property, so the restore is inert
        // there.
        foreach ($parents as $parent) {
            Accessor::set($parent, $column, $snapshots[\spl_object_id($parent)][$column] ?? null);
        }

        // The page-1 synthetic request and the merged criteria are resolved once for the
        // relation — they do not vary per parent (page is OUT for includes, fixed at page
        // 1; the relatedQuery sort/filter ride as the plain `?sort`/`?filter`). The merge
        // is owned by the shared RelationCriteriaFactory; includes never pivot.
        $pageRequest = $this->page1Request($request, $relatedQuery);
        $window = $paginator?->window($pageRequest);

        // A windowed include is counted only when the pagination itself counts — the
        // relation's paginator opted in (`withCount()`) or the client named the relation
        // in `?withCount` (`countable()` is the upstream gate for that). A count-free
        // paginator default emits `first`/`prev`/`next` with no total/`last`, exactly
        // like the primary and related-collection endpoints (G21; supersedes the
        // slice-1 "countable() always counts on include" of bundle ADR 0053). The
        // `?withCount` flag rides the ORIGINAL request, not the synthetic page request.
        $wantsCount = ($paginator?->wantsCount() ?? false) || $request->countsRelationship($relation->name());

        $criteria = $this->relationCriteria->criteriaFor(
            new QueryParameters(
                fields: [],
                includes: [],
                sort: $relatedQuery->sortFields(),
                filter: $relatedQuery->filter,
                pagination: $pageRequest->getPagination(),
            ),
            $relatedResource,
            $relation,
            $window,
            includePivotFields: false,
            wantsCount: $wantsCount,
        );

        // Validate the relatedQuery filter VALUE against the per-relation merged
        // vocabulary before the batched window fetch, so a mistyped value on the
        // include/linkage path is the endpoint's same 400 (bundle ADR 0068 follow-up #2).
        // RAW client input only (RelatedQuery::filter); a no-op without the validator.
        $this->filterValues?->validate($relatedQuery->filter, $criteria->filters);

        // ONE batched fetch for the whole page, driven through the PRIMARY type's
        // provider — like RelationCountBatcher, and so the provider keys each parent's
        // page by the parent's own WIRE id (the in-memory witness identifies the parent
        // through ITS store; the Doctrine reference is one provider for all types). The
        // per-parent scoping, merged-vocabulary key validation (unknown key -> the
        // endpoint's 400), and the count-free vs countable distinction are all reused
        // verbatim from fetchRelatedCollection's tail.
        $batch = $this->providers->forType($type)
            ->fetchRelatedCollectionBatch($type, $parents, $relation, $criteria, $pageRequest);

        $serializer = $server->serializerFor($type);

        $windows = [];
        foreach ($parents as $parent) {
            $result = $batch->for($serializer->getId($parent));
            $windows[\spl_object_id($parent)] = $this->windowFor(
                $paginator,
                $pageRequest,
                $result,
                $relatedQuery,
                $snapshots[\spl_object_id($parent)][$column] ?? null,
                $wantsCount,
                $server,
                $relatedType,
            );
        }

        return $windows;
    }

    /**
     * Builds one parent's windowed page-1 result: the {@see RelationshipLinkage} core
     * renders out-of-band (so the page is NOT written onto the parent property, leaving
     * any column-sharing sibling relation to render its own membership — bundle ADR
     * 0086) paired with its {@see RelationshipPagination} — or a `null` page when the
     * relation is not sliced (a relatedQuery present but no paginator), in which case the
     * linkage is re-ordered/filtered but carries no pagination links.
     *
     * The page is wrapped to match the column's container (a Doctrine `Collection`
     * property cannot take a raw array; `$snapshot` carries the container type), so the
     * linkage the serializer reads is the same shape as the property it replaces.
     *
     * @param CollectionResult<object> $result
     *
     * @return array{page: ?RelationshipPagination, linkage: RelationshipLinkage}
     */
    private function windowFor(
        ?PaginatorInterface $paginator,
        JsonApiRequestInterface $pageRequest,
        CollectionResult $result,
        RelatedQuery $relatedQuery,
        mixed $snapshot,
        bool $wantsCount,
        Server $server,
        string $relatedType,
    ): array {
        $items = \is_array($result->items) ? \array_values($result->items) : \iterator_to_array($result->items, false);

        // Supply page 1 as the relation's linkage out-of-band — the `sort` selects which
        // members land on page 1 (page is out for includes). Wrapped to the column's
        // container so the serializer reads the same shape it would off the property.
        $linkage = new RelationshipLinkage($this->asContainer($items, $snapshot));

        // No paginator: re-ordered/filtered but not sliced, so there is no page to
        // navigate and no pagination links to emit — only the linkage override.
        $page = $paginator === null
            ? null
            : $this->paginationFor($paginator, $pageRequest, $items, $result, $relatedQuery, $wantsCount, $server, $relatedType);

        return ['page' => $page, 'linkage' => $linkage];
    }

    /**
     * The to-many relations among `$allRelations` that may be windowed under the
     * profile: a monomorphic to-many whose linkage DATA renders for THIS request.
     * A polymorphic to-many (members span types — no single related provider or shared
     * vocabulary) and a to-one are excluded; the related/relationship endpoints still
     * serve them directly.
     *
     * A to-many's data renders for this request when it is INCLUDED at the primary
     * level (`isIncludedRelationship('', name, defaults)` — honouring default-includes)
     * OR it renders data UNCONDITIONALLY (`emitsDataOnlyWhenLoaded() === false`, the
     * `withData()` opt-out). A lazy, links-only, not-included to-many is excluded
     * (bundle ADR 0086): windowing it would write page 1 onto the parent property,
     * flipping the storage load-state predicate to "loaded" — so core's
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::shouldDeferLinkage()} stops
     * deferring and the relationship leaks the filtered linkage the lazy default omits,
     * for a relation the client never included; gating the window closes that leak (and
     * the wasted fetch) while leaving links-only rendering intact. The COUNT path
     * ({@see RelationCountBatcher}) is unaffected — a filtered `?withCount` on a
     * not-included to-many still counts the filtered set without loading.
     *
     * Includes are resolved off `$representative` (the page's first parent), mirroring
     * {@see RelatedIncludeBatcher}: `getDefaultIncludedRelationships()` is a type-level
     * declaration, so the representative suffices for the per-type default cascade.
     *
     * @param list<RelationInterface> $allRelations
     *
     * @return list<RelationInterface>
     */
    private function windowableRelations(
        Server $server,
        string $type,
        array $allRelations,
        object $representative,
        JsonApiRequestInterface $request,
    ): array {
        $defaults = \array_flip($server->serializerFor($type)->getDefaultIncludedRelationships($representative));

        $relations = [];
        foreach ($allRelations as $relation) {
            if (!$relation->isToMany()) {
                continue;
            }

            // Polymorphic to-many: members span entity classes, so there is no
            // single related provider to window through — leave it to its endpoint.
            if (\count($relation->relatedTypes()) > 1) {
                continue;
            }

            // Window only when this to-many's linkage data renders for the request:
            // included at the primary level (default-includes honoured) or withData.
            if (
                $relation->emitsDataOnlyWhenLoaded()
                && !$request->isIncludedRelationship('', $relation->name(), $defaults)
            ) {
                continue;
            }

            $relations[] = $relation;
        }

        return $relations;
    }

    /**
     * Validates the relatedQuery sort/filter KEYS of every monomorphic to-many that is
     * addressed by a relatedQuery but is NOT being windowed this request (bundle ADR
     * 0086): a links-only, not-included to-many that the rendered-data gate excluded
     * from {@see windowableRelations()}. The window fetch normally surfaces an unknown
     * key as the related-collection endpoint's `400` — but a gated relation never
     * fetches, so the keys are validated here directly against the SAME merged
     * vocabulary the fetch would have ({@see RelationCriteriaFactory::criteriaFor()}),
     * with NO query and NO property mutation. So a typo'd `relatedQuery[<rel>][filter]`/
     * `[sort]` key on a links-only relation is still the endpoint's same `400` (an
     * unknown KEY is a client error regardless of whether the linkage renders), while a
     * valid key on a links-only relation is a clean links-only render (no leak, no
     * query). The windowed relations validate their keys through the fetch as before, so
     * this covers only the gated remainder.
     *
     * @param list<RelationInterface> $allRelations every declared relation of the type
     * @param list<RelationInterface> $windowed     the rendered-data relations being windowed (skipped here)
     */
    private function validateUnwindowedRelatedQueryKeys(
        Server $server,
        string $type,
        array $allRelations,
        array $windowed,
        JsonApiRequestInterface $request,
    ): void {
        $windowedNames = [];
        foreach ($windowed as $relation) {
            $windowedNames[$relation->name()] = true;
        }

        foreach ($allRelations as $relation) {
            // Only monomorphic to-many relations are windowable; a to-one is handled by
            // the nulling path and a polymorphic to-many carries no shared vocabulary.
            if (!$relation->isToMany() || \count($relation->relatedTypes()) > 1) {
                continue;
            }

            // Skip the relations the fetch already validates (the windowed set).
            if (isset($windowedNames[$relation->name()])) {
                continue;
            }

            $relatedQuery = $request->getRelatedQuery($relation->name());
            if ($relatedQuery->isEmpty()) {
                continue;
            }

            $relatedType = $relation->relatedTypes()[0] ?? null;
            $relatedResource = $relatedType !== null && $this->providers->supportsType($relatedType)
                ? $this->types->resourceFor($server, $relatedType)
                : null;

            $criteria = $this->relationCriteria->criteriaFor(
                new QueryParameters(
                    fields: [],
                    includes: [],
                    sort: $relatedQuery->sortFields(),
                    filter: $relatedQuery->filter,
                    pagination: $request->getPagination(),
                ),
                $relatedResource,
                $relation,
                null,
                includePivotFields: false,
            );

            $this->assertRelatedQueryKeysRecognised($relatedQuery, $criteria);

            // The mistyped-VALUE check rides the same path as the windowed fetch (bundle
            // ADR 0068 follow-up #2): a links-only relation validates its raw filter
            // values too, so a bad value is the endpoint's same `400` here as well.
            $this->filterValues?->validate($relatedQuery->filter, $criteria->filters);
        }
    }

    /**
     * Throws the related-collection endpoint's same `400` for an unrecognised relatedQuery
     * filter/sort key, mirroring {@see CriteriaApplier}'s key recognition exactly (so the
     * gated links-only path and the fetched windowed path agree): each requested filter key
     * must name a declared {@see \haddowg\JsonApi\Resource\Filter\FilterInterface}; each
     * requested sort field (with any leading `-` stripped) must name a declared
     * {@see \haddowg\JsonApi\Resource\Sort\SortInterface}, and a sort requested against a
     * relation that declares none is {@see \haddowg\JsonApi\Exception\SortingUnsupported}.
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized on an unrecognised filter key
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   on an unrecognised sort field
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported      when a sort is requested but none are declared
     */
    private function assertRelatedQueryKeysRecognised(RelatedQuery $relatedQuery, CollectionCriteria $criteria): void
    {
        foreach (\array_keys($relatedQuery->filter) as $key) {
            $key = (string) $key;
            if (!$this->declaresFilter($criteria->filters, $key)) {
                throw new \haddowg\JsonApi\Exception\FilterParamUnrecognized($key);
            }
        }

        $sortFields = $relatedQuery->sortFields();
        if ($sortFields === []) {
            return;
        }

        if ($criteria->sorts === []) {
            throw new \haddowg\JsonApi\Exception\SortingUnsupported();
        }

        foreach ($sortFields as $field) {
            $key = \str_starts_with($field, '-') ? \substr($field, 1) : $field;
            if (!$this->declaresSort($criteria->sorts, $key)) {
                throw new \haddowg\JsonApi\Exception\SortParamUnrecognized($key);
            }
        }
    }

    /**
     * Whether the criteria's declared filters carry one keyed `$key`.
     *
     * @param list<\haddowg\JsonApi\Resource\Filter\FilterInterface> $filters
     */
    private function declaresFilter(array $filters, string $key): bool
    {
        foreach ($filters as $filter) {
            if ($filter->key() === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the criteria's declared sorts carry one keyed `$key`.
     *
     * @param list<\haddowg\JsonApi\Resource\Sort\SortInterface> $sorts
     */
    private function declaresSort(array $sorts, string $key): bool
    {
        foreach ($sorts as $sort) {
            if ($sort->key() === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The monomorphic TO-ONE relations among `$allRelations` addressed by a
     * filter-only `relatedQuery`/`rQ` param (bundle ADR 0068): a to-one whose path
     * carries a `[filter]` op (and whose raw family ops are filter-only — a `[sort]`/
     * `[page]` would have 400'd in {@see validatePaths()}). A polymorphic to-one carries
     * no shared filter vocabulary, so it is excluded.
     *
     * @param list<RelationInterface> $allRelations
     *
     * @return list<RelationInterface>
     */
    private function toOneFilteredRelations(array $allRelations, JsonApiRequestInterface $request): array
    {
        $filtered = [];
        foreach ($allRelations as $relation) {
            if ($relation->isToMany() || \count($relation->relatedTypes()) > 1) {
                continue;
            }

            if ($request->getRelatedQuery($relation->name())->filter !== []) {
                $filtered[] = $relation;
            }
        }

        return $filtered;
    }

    /**
     * Nulls each filter-excluded to-one's linkage across the whole page in ONE batched
     * match per relation (bundle ADR 0068): for each filtered to-one it resolves the
     * merged filter criteria (related resource ⊕ relation filters, the same vocabulary
     * the related endpoint uses), drives the provider's
     * {@see DataProviderInterface::relatedToOneMatchesBatch()} ONCE over the page (no
     * N+1), and writes `null` onto the to-one property of each parent whose target does
     * not match — so the rendered linkage is `null` and, because the include preloader
     * already wrote the target onto the property, the include is omitted. A GET read, so
     * nothing flushes. The to-one nulling produces no pagination entry (a to-one has no
     * pagination).
     *
     * @param list<object>            $parents
     * @param list<RelationInterface> $toOneFiltered
     */
    private function nullExcludedToOnes(
        Server $server,
        string $type,
        array $parents,
        array $toOneFiltered,
        JsonApiRequestInterface $request,
    ): void {
        foreach ($toOneFiltered as $relation) {
            $relatedType = $relation->relatedTypes()[0] ?? null;
            if ($relatedType === null || !$this->providers->supportsType($relatedType)) {
                continue;
            }

            $relatedResource = $this->types->resourceFor($server, $relatedType);
            $criteria = $this->relationCriteria->criteriaFor(
                new QueryParameters(
                    fields: [],
                    includes: [],
                    sort: [],
                    filter: $request->getRelatedQuery($relation->name())->filter,
                    pagination: $request->getPagination(),
                ),
                $relatedResource,
                $relation,
                null,
                includePivotFields: false,
            );

            // Validate the relatedQuery filter VALUE against the per-relation merged
            // vocabulary before the batched match runs, so a mistyped value on the
            // include/linkage path is the endpoint's same 400 (bundle ADR 0068 follow-up
            // #2). RAW client input only (RelatedQuery::filter), so an author default is
            // never validated (ADR 0048); a no-op without the validator.
            $this->filterValues?->validate($request->getRelatedQuery($relation->name())->filter, $criteria->filters);

            $matches = $this->providers->forType($type)
                ->relatedToOneMatchesBatch($type, $parents, $relation, $criteria, $request);

            $serializer = $server->serializerFor($type);
            $column = $relation->column() ?? $relation->name();
            foreach ($parents as $parent) {
                if (($matches[$serializer->getId($parent)] ?? false) === false) {
                    Accessor::set($parent, $column, null);
                }
            }
        }
    }

    /**
     * Rejects any addressed `relatedQuery`/`rQ` path that is not a windowable
     * (monomorphic to-many) relation of the primary type — an unknown relationship
     * path (including a dotted path the batcher does not address, which only windows
     * top-level relations) or a to-one path addressed with a `[sort]`/`[page]` op
     * (a single member has nothing to order or page) — with the related-collection
     * endpoint's same `400`. A monomorphic to-one addressed with `[filter]` ONLY is
     * ALLOWED through (bundle ADR 0068): its single target is matched against the
     * filters and the linkage nulled when excluded.
     *
     * `source.parameter` names the parameter as the client wrote it — the family base
     * it used (`relatedQuery[<path>]` or `rQ[<path>]`), matching core's structural
     * errors rather than normalising to the canonical family. The unknown sort/filter
     * KEY on a valid path is still validated downstream by the fetch (core ADR 0058
     * delegates path/cardinality validation to the host).
     *
     * Core's `parseRelatedQueries()` captures only `sort`+`filter` ops and silently
     * drops a `[page]` op, so a `[page]`-on-to-one cannot be seen through
     * `getRelatedQueryPaths()`/`RelatedQuery` — this reads the RAW
     * `getQueryParam('relatedQuery'/'rQ')` family (populated only when the profile is
     * negotiated) to detect a `[sort]`/`[page]` op on a to-one path.
     *
     * @param list<RelationInterface> $allRelations every declared relation of the type
     */
    private function validatePaths(array $allRelations, JsonApiRequestInterface $request): void
    {
        $windowable = [];
        $toOne = [];
        foreach ($allRelations as $relation) {
            if ($relation->isToMany() && \count($relation->relatedTypes()) === 1) {
                $windowable[$relation->name()] = true;
            } elseif (!$relation->isToMany() && \count($relation->relatedTypes()) === 1) {
                $toOne[$relation->name()] = true;
            }
        }

        // Validate the union of the PARSED paths (sort/filter ops) and the RAW family
        // paths: core's parser drops a `[page]` op, so a page-only path is invisible to
        // getRelatedQueryPaths() — but a `[page]`-on-to-one must still 400, so the raw
        // family is scanned too (bundle ADR 0068). A path's raw op set decides a to-one's
        // fate: filter-only passes, any sort/page is rejected.
        $rawOps = $this->rawPathOps($request);
        $rawFamilies = $this->rawPathFamilies($request);
        $paths = [...$request->getRelatedQueryPaths(), ...\array_keys($rawOps)];

        $seen = [];
        foreach ($paths as $path) {
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            if (isset($windowable[$path])) {
                continue;
            }

            // A monomorphic to-one path is allowed only when its raw family ops are
            // filter-only — a `[sort]` or `[page]` addressed to a to-one is a 400.
            if (isset($toOne[$path]) && ($rawOps[$path] ?? []) === ['filter']) {
                continue;
            }

            // Name the parameter as the client wrote it: the family base it used
            // (`rQ` when only the shorthand addressed the path), so the error source
            // points at what was sent rather than a normalised canonical form.
            $family = $rawFamilies[$path] ?? RelationshipQueriesProfile::FAMILY;
            throw new QueryParamUnrecognized($family . '[' . $path . ']');
        }
    }

    /**
     * The family base (`relatedQuery` or `rQ`) the client used to address each
     * `relatedQuery`/`rQ` path, so an error names the parameter as written rather than
     * normalising to the canonical family (core's structural errors do the same). The
     * canonical `relatedQuery` is recorded first, so a path addressed by BOTH families
     * is reported against the canonical one (the profile's canonical-wins rule), while
     * a path addressed only by the shorthand is reported against `rQ`.
     *
     * @return array<string, string> `path => family base`
     */
    private function rawPathFamilies(JsonApiRequestInterface $request): array
    {
        $families = [];
        foreach ([RelationshipQueriesProfile::FAMILY, RelationshipQueriesProfile::FAMILY_SHORTHAND] as $family) {
            $value = $request->getQueryParam($family);
            if (!\is_array($value)) {
                continue;
            }

            foreach (\array_keys($value) as $path) {
                $families[(string) $path] ??= $family;
            }
        }

        return $families;
    }

    /**
     * The raw op set per relatedQuery path across BOTH families (`relatedQuery` + `rQ`),
     * read straight off the raw `getQueryParam()` query param — NOT the parsed
     * `RelatedQuery`, which drops a `[page]` op. So a path with a `[page]` (or `[sort]`)
     * op is visible here even when core's parser ignored it, letting a `[page]`/`[sort]`
     * on a to-one be a 400 while a filter-only path passes. Each path's ops are sorted so
     * a filter-only path is exactly `['filter']`.
     *
     * @return array<string, list<string>> `path => sorted op keys`
     */
    private function rawPathOps(JsonApiRequestInterface $request): array
    {
        $ops = [];
        foreach ([RelationshipQueriesProfile::FAMILY, RelationshipQueriesProfile::FAMILY_SHORTHAND] as $family) {
            $value = $request->getQueryParam($family);
            if (!\is_array($value)) {
                continue;
            }

            foreach ($value as $path => $pathOps) {
                if (!\is_array($pathOps)) {
                    continue;
                }

                foreach (\array_keys($pathOps) as $op) {
                    $ops[(string) $path][(string) $op] = true;
                }
            }
        }

        $result = [];
        foreach ($ops as $path => $opSet) {
            $keys = \array_keys($opSet);
            \sort($keys);
            $result[$path] = $keys;
        }

        return $result;
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
     * Builds the {@see RelationshipPagination} for the windowed page: when the
     * pagination counts (`$wantsCount` — the paginator's `withCount()` or a client
     * `?withCount`), it emits the total + `first`/`prev`/`next`/`last`; otherwise it
     * paginates count-free (no total, no `last`; `next` driven by the `hasMore` probe).
     * The plain-form query string carries the relatedQuery sort/filter so the links
     * mirror them.
     *
     * @param CollectionResult<object> $result
     * @param list<object>             $items
     */
    private function paginationFor(
        PaginatorInterface $paginator,
        JsonApiRequestInterface $pageRequest,
        array $items,
        CollectionResult $result,
        RelatedQuery $relatedQuery,
        bool $wantsCount,
        Server $server,
        string $relatedType,
    ): RelationshipPagination {
        $queryString = $relatedQuery->toPlainQueryString();

        // A cursor (keyset) window on an included relation: the provider minted the
        // per-parent boundary tokens (an include is always a FIRST cursor page — no
        // cursor token rides an include — so `hasPrevious` is false and `prev` is
        // omitted), so render through the paginator's cursor path carrying the minted
        // `next` token + `hasMore`, mirroring the primary/related cursor render (core
        // ADR 0063). `from`/`to` are the wire ids of the first/last rendered rows — the
        // related serializer is resolved only here (a cursor-resolved relation's related
        // type is always servable), never eagerly for every windowed relation.
        if ($result instanceof CursorCollectionResult && $paginator instanceof CursorPaginator) {
            $relatedSerializer = $server->serializerFor($relatedType);
            $from = $items === [] ? null : $relatedSerializer->getId($items[0]);
            $to = $items === [] ? null : $relatedSerializer->getId($items[\array_key_last($items)]);

            return new RelationshipPagination(
                $paginator->fromBoundaries(
                    $pageRequest,
                    $items,
                    $result->cursorBefore ?? '',
                    $result->cursorAfter ?? '',
                    $result->hasMore,
                    $result->hasPrevious,
                    from: $from,
                    to: $to,
                ),
                $queryString,
            );
        }

        if ($wantsCount && $result->total !== null) {
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
}
