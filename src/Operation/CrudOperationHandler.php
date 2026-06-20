<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\SupportsSingular;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Action\ActionInvoker;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\PivotAwareProviderInterface;
use haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiBundle\DataProvider\RelationCountBatcher;
use haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiBundle\DataProvider\RelationshipWindowBatcher;
use haddowg\JsonApiBundle\Event\AfterCreateEvent;
use haddowg\JsonApiBundle\Event\AfterDeleteEvent;
use haddowg\JsonApiBundle\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiBundle\Event\AfterFetchOneEvent;
use haddowg\JsonApiBundle\Event\AfterRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\AfterSaveEvent;
use haddowg\JsonApiBundle\Event\AfterUpdateEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use haddowg\JsonApiBundle\Serializer\PivotMetaSerializer;
use haddowg\JsonApiBundle\Serializer\PivotParentSerializer;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipPagination;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use haddowg\JsonApiBundle\Validation\FilterValueValidator;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The generic CRUD handler the {@see \haddowg\JsonApiBundle\Server\ServerFactory}
 * wires via `Server::withHandler()`, so `Server::dispatch($operation)` has a
 * target. It dispatches on the operation type to the per-type
 * {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface} (reads) and
 * {@see \haddowg\JsonApiBundle\DataPersister\DataPersisterInterface} (writes)
 * resolved from their registries.
 *
 * Reads ({@see FetchResourceOperation}): a single fetch maps a missing resource
 * to a `404`; a collection fetch resolves the resource's declared `filters()`,
 * `allSorts()`, `pagination()` into a {@see CollectionCriteria}, asks the provider
 * to execute it, and renders a paginated {@see DataResponse::fromPage()} (else a
 * plain collection).
 *
 * Writes share one shape — resolve the persister, drive core's per-type hydrator
 * ({@see Server::hydratorFor()}), commit, render:
 *  - {@see CreateResourceOperation} hydrates a fresh {@see DataPersisterInterface::instantiate()}
 *    instance from the request body and persists it, rendering `201` with a
 *    `Location` header;
 *  - {@see UpdateResourceOperation} loads the target through the read provider
 *    (a `404` when absent), hydrates the body onto it, and persists it (`200`);
 *  - {@see DeleteResourceOperation} loads the target (a `404` when absent),
 *    deletes it, and renders `204`.
 *
 * A `data.relationships` member on a create/update is **not** hydrated by core (a
 * scalar linkage id would land on a typed association property): the handler strips
 * it from the body so core hydrates only id + attributes, then sets each named
 * association through the persister's relationship seam
 * ({@see DataPersisterInterface::mutateRelationship()}, {@see Mode::Replace}) with
 * the commit deferred so the single {@see DataPersisterInterface::create()}/
 * {@see DataPersisterInterface::update()} flush owns it — a not-yet-persisted create
 * target is never flushed mid-association (ADR 0018).
 *
 * Relationship reads share the same provider-resolution shape: load the parent
 * through the read provider (a `404` when absent), resolve the named relation on
 * the parent resource via core's {@see \haddowg\JsonApi\Resource\AbstractResource::relationNamed()}
 * (a JSON:API `404` {@see RelationshipNotExists} when the relationship is unknown
 * or its endpoint is suppressed — relationship routes stay parametric, so the
 * handler enforces each relation's endpoint-exposure flags, ADR 0027), then
 * render —
 *  - {@see FetchRelatedOperation} renders the related domain value(s) through the
 *    *related* type's serializer: a single resource for a to-one (read off the
 *    parent via {@see RelationInterface::readValue()},
 *    {@see RelatedResponse::fromResource()}, `data:null` when empty), or — for a
 *    to-many — a queryable, paginated collection resolved over the related
 *    provider's {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface::fetchRelatedCollection()}
 *    seam ({@see RelatedResponse::fromPage()}, else {@see RelatedResponse::fromCollection()});
 *  - {@see FetchRelationshipOperation} routes the parent through the *parent*
 *    type's serializer with the relationship name set, emitting linkage only
 *    ({@see IdentifierResponse::forRelationship()}).
 *
 * Relationship mutations ({@see UpdateRelationshipOperation} replace,
 * {@see AddToRelationshipOperation} add, {@see RemoveFromRelationshipOperation}
 * remove) share that parent-load + relation-resolve shape, then validate the
 * request shape against the relation (cardinality + the
 * {@see RelationInterface::allowsReplace()}/{@see RelationInterface::allowsRemove()}
 * mutability flags, throwing core's typed `400`/`403`s) and delegate the
 * storage-correct apply to the persister's relationship seam
 * ({@see DataPersisterInterface::mutateRelationship()}) — core owns the rules, the
 * persister owns the id → object/reference resolution and the association write
 * (ADR 0017) — rendering the resulting linkage (`200`).
 *
 * Core's typed exceptions (unknown filter/sort keys, hydration failures, the
 * validator bridge's `422`) propagate to the route-scoped `kernel.exception`
 * listener, which owns all error rendering on JSON:API routes.
 *
 * This is the generic, zero-per-type-handler CRUD engine: it drives every
 * registered type through the Provider/Persister SPIs and the per-type
 * serializer/hydrator, resolving each type's declarative metadata through the
 * {@see TypeMetadataResolver} seam so a type with no resource (a bare
 * serializer/hydrator pair) is tolerated without per-type branching — the
 * capstone (bundle ADR 0021). Per-type customization composes through the SPIs
 * (a higher-priority provider/persister), the serializer/hydrator overrides, or
 * decorating this handler; no per-type handler code is required.
 */
final class CrudOperationHandler implements \haddowg\JsonApi\Operation\OperationHandlerInterface
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly TypeMetadataResolver $types,
        private readonly RelationCriteriaFactory $relationCriteria,
        private readonly ?ResourceValidator $validator = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?FilterValueValidator $filterValues = null,
        private readonly ?RelationCountBatcher $countBatcher = null,
        private readonly ?RequestScopedRelationshipCount $relationshipCount = null,
        private readonly ?RelationshipWindowBatcher $windowBatcher = null,
        private readonly ?RequestScopedRelationshipPagination $relationshipPagination = null,
        private readonly ?RelatedIncludeBatcher $includeBatcher = null,
        private readonly ?ActionInvoker $actions = null,
    ) {}

    public function handle(\haddowg\JsonApi\Operation\JsonApiOperationInterface $operation): DataResponse|RelatedResponse|IdentifierResponse|MetaResponse|NoContentResponse|ErrorResponse
    {
        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
            $operation instanceof FetchRelatedOperation => $this->fetchRelated($operation),
            $operation instanceof FetchRelationshipOperation => $this->fetchRelationship($operation),
            $operation instanceof CreateResourceOperation => $this->create($operation),
            $operation instanceof UpdateResourceOperation => $this->update($operation),
            $operation instanceof DeleteResourceOperation => $this->delete($operation),
            $operation instanceof UpdateRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Replace),
            $operation instanceof AddToRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Add),
            $operation instanceof RemoveFromRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Remove),
            $operation instanceof CustomActionOperation => $this->actions?->invoke($operation) ?? ErrorResponse::fromException(new ResourceNotFound()),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    private function fetch(FetchResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $provider = $this->providers->forType($type);
        $serializer = $server->serializerFor($type);

        $request = $operation->context()->httpRequest();
        $request = $request instanceof JsonApiRequestInterface ? $request : null;

        $id = $operation->target()->id;
        if ($id !== null) {
            $model = $provider->fetchOne($type, $id);
            if ($model === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            // Batch eager-load the effective ?include tree (explicit or the
            // resource's default-include fallback) so includes do not N+1 against a
            // provider that opts into the capability (the Doctrine reference does);
            // a single resource is preloaded as a one-element list (ADR 0035).
            $this->preloadIncludes($provider, [$model], $type, $request);

            // Under the Relationship Queries profile, window each rendered to-many
            // relation to page 1 of its profile-ordered/filtered set (the relatedQuery
            // sort/filter) and install the relationship-object pagination links seam
            // (bundle ADR 0053). A no-op when the profile is not negotiated.
            $this->applyRelationshipWindows($server, $type, [$model], $request);

            // Install the ?withCount batched counts for this single resource so its
            // relationship objects render meta.total (bundle ADR 0052).
            $this->applyRelationshipCounts($server, $type, [$model], $request);

            $response = DataResponse::fromResource($model, $serializer);

            // The after-fetch-one hook (post-fetch) may replace the response — a
            // custom-action shaping of the read. Skipped for a programmatic dispatch
            // with no request to build the event from.
            if ($request !== null) {
                $event = new AfterFetchOneEvent($type, $request, $model, $this->serverName($request));
                $this->dispatch($event);
                $response = $event->response() ?? $response;
            }

            return $response;
        }

        // A bare serializer/hydrator pair declares no field inventory, so it has
        // no filter/sort vocabulary and no resource-level paginator.
        $resource = $this->types->resourceFor($server, $type);

        $filters = $resource?->filters() ?? [];

        // Validate each client-supplied filter value against the declared value
        // constraints before the filter reaches the provider, so a mistyped value
        // (filter[id]=banana on an integer column) is a clean 400 rather than the
        // provider's silent non-match (or, on a strict driver, a PDO 500). A no-op
        // without the optional validator, or for a filter with no declared
        // constraints; only the raw requested values are checked, never an
        // author-set default (ADR 0048).
        $this->validateFilterValues($operation->queryParameters()->filter, $filters);

        // A singular filter the client applied collapses the collection to a
        // zero-to-one response — a single resource (the first match) or null,
        // never an array, and never paginated (core ADR 0039).
        $singular = $this->appliesSingularFilter($filters, $operation->queryParameters());

        // The resource's pagination() return is the single source of truth (G21):
        // used verbatim, with `null` meaning *no pagination* (fetch-all). The base
        // impl returns the resolved server default, so a non-overriding resource still
        // inherits it; a bare serializer/hydrator pair (no resource) has no override
        // and falls back to the server default directly. A singular filter collapses
        // to a zero-to-one response, so it is never paginated.
        $paginator = $singular ? null : ($resource !== null ? $resource->pagination($server->defaultPaginator()) : $server->defaultPaginator());
        $window = $paginator !== null && $request !== null ? $paginator->window($request) : null;

        // The single COUNT decision (G21): a count-based paginator counts when its
        // own withCount() author opt-in flipped it, OR the client asked
        // `?withCount=_self_` (already 400-ed by core's document gate if the resource
        // is not countable(), so an accepted `_self_` here implies countable). The
        // cursor strategy is inherently count-free and resolves a total-null page on
        // its own branch, so it is excluded.
        $wantsCount = $paginator !== null
            && !($paginator instanceof CursorPaginator)
            && ($paginator->wantsCount() || ($request !== null && $request->countsRelationship('_self_')));

        $result = $provider->fetchCollection($type, new CollectionCriteria(
            $operation->queryParameters(),
            $filters,
            $resource?->allSorts() ?? [],
            $window,
            // Applied only when the request carries no `sort` (core ADR 0044); a
            // bare serializer/hydrator pair has no resource and so no default.
            $resource?->defaultSort() ?? [],
            wantsCount: $wantsCount,
        ));

        // Materialize once so the items can be both preloaded and rendered (and a
        // singular filter can peek the first without consuming a one-shot iterator).
        $items = \is_array($result->items) ? \array_values($result->items) : \iterator_to_array($result->items, false);

        if ($singular) {
            $first = $items[0] ?? null;
            if ($first !== null) {
                $this->preloadIncludes($provider, [$first], $type, $request);
            }

            // A singular filter collapses to a single resource; window + count it too.
            $this->applyRelationshipWindows($server, $type, $first === null ? [] : [$first], $request);
            $this->applyRelationshipCounts($server, $type, $first === null ? [] : [$first], $request);

            return $this->afterFetchCollection(
                DataResponse::fromResource($first, $serializer),
                $type,
                $request,
                $items,
            );
        }

        // Batch eager-load the effective ?include tree across the whole page/collection
        // so includes do not N+1 (ADR 0035).
        $this->preloadIncludes($provider, $items, $type, $request);

        // Under the profile, window each parent's rendered to-many relations to page
        // 1 of their profile-ordered/filtered set — the per-parent windowed page 1 of
        // a collection include (bundle ADR 0053). A no-op when the profile is absent.
        $this->applyRelationshipWindows($server, $type, $items, $request);

        // Install the ?withCount batched counts across the whole page in ONE grouped
        // count per relation (no N+1), so every parent's relationship objects render
        // meta.total (bundle ADR 0052).
        $this->applyRelationshipCounts($server, $type, $items, $request);

        // A cursor (keyset) page: the provider minted the boundary tokens (it owns
        // the row → boundary-value reader), so render through the paginator's cursor
        // path (CursorPaginator::fromBoundaries) carrying the pre-minted prev/next
        // tokens + the has-flags. `from`/`to` are the wire ids of the first/last
        // rendered rows (meta.page.from/to). This is the only total-null primary
        // path (a primary collection is otherwise always countable), so the offset
        // and fromCollection branches below stay byte-identical (bundle ADR 0063).
        if ($result instanceof CursorCollectionResult && $paginator instanceof CursorPaginator && $request !== null) {
            $from = $items === [] ? null : $serializer->getId($items[0]);
            $to = $items === [] ? null : $serializer->getId($items[\array_key_last($items)]);

            $response = DataResponse::fromPage(
                $paginator->fromBoundaries(
                    $request,
                    $items,
                    $result->cursorBefore ?? '',
                    $result->cursorAfter ?? '',
                    $result->hasMore,
                    $result->hasPrevious,
                    from: $from,
                    to: $to,
                ),
                $serializer,
            );

            return $this->afterFetchCollection($response, $type, $request, $items);
        }

        // Fan the single resolved total to BOTH meta slots from one count, per the
        // G21 §6a matrix (never recount):
        //
        // - FETCH-ALL (no paginator): the whole collection is materialized, so its
        //   size is free — render `meta.total` UNCONDITIONALLY, no `meta.page` (§5).
        // - PAGINATED + counted (`$result->total !== null`): the count-based page
        //   carries `meta.page.total`/`links.last`; the SAME int is echoed at
        //   top-level `meta.total` — never a second count.
        // - PAGINATED + count-free (`windowed`, total null): render a count-free page
        //   (self/first/prev/next, no total/last; `next` via `hasMore`) and NO
        //   `meta.total` — counting is opt-in (mirrors the related path).
        if ($paginator === null) {
            $response = DataResponse::fromCollection($items, $serializer)
                ->withMeta(['total' => \count($items)]);
        } elseif ($request !== null && $result->total !== null) {
            $response = DataResponse::fromPage($paginator->paginate($request, $items, $result->total), $serializer)
                ->withMeta(['total' => $result->total]);
        } elseif ($request !== null && $result->windowed) {
            $response = DataResponse::fromPage($paginator->paginateWithoutCount($request, $items, $result->hasMore), $serializer);
        } else {
            $response = DataResponse::fromCollection($items, $serializer);
        }

        return $this->afterFetchCollection($response, $type, $request, $items);
    }

    /**
     * Fires the after-fetch-collection hook (post-fetch), letting a subscriber/hook
     * replace the response. Skipped — the handler's response returned unchanged —
     * for a programmatic dispatch with no request to build the event from.
     *
     * @param list<object> $items the materialized collection
     */
    private function afterFetchCollection(DataResponse $response, string $type, ?JsonApiRequestInterface $request, array $items): DataResponse
    {
        if ($request === null) {
            return $response;
        }

        $event = new AfterFetchCollectionEvent($type, $request, $items, $this->serverName($request));
        $this->dispatch($event);

        return $event->response() ?? $response;
    }

    /**
     * Whether the client applied a filter the resource declares
     * {@see SupportsSingular singular} — the trigger to collapse the collection to
     * a zero-to-one ({@see DataResponse::fromResource()}) response.
     *
     * @param list<FilterInterface> $filters
     */
    private function appliesSingularFilter(array $filters, QueryParameters $queryParameters): bool
    {
        foreach ($filters as $filter) {
            if ($filter instanceof SupportsSingular
                && $filter->isSingular()
                && \array_key_exists($filter->key(), $queryParameters->filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `GET /{type}/{id}/{relationship}` — the related-resource(s) document. Loads
     * the parent, resolves the named relation, and renders through the related
     * type's serializer per cardinality:
     *  - a to-one reads the related object off the parent and resolves the
     *    serializer **from that object** ({@see RelationInterface::resolveSerializer()}),
     *    so a polymorphic to-one ({@see \haddowg\JsonApi\Resource\Field\MorphTo})
     *    renders the related object's own type; it renders a single resource
     *    (`data:null` for an empty to-one — `resolveSerializer(null, …)` falls back
     *    to the first registered serializer, valid for the null render);
     *  - a to-many resolves the *related* type's filter/sort/pagination vocabulary —
     *    merged with the relation's own scoped {@see RelationInterface::filters()}/
     *    {@see RelationInterface::sorts()} (extra filters/sorts available ONLY on this
     *    related endpoint, never the primary collection; the relation wins a key
     *    clash, core ADR 0051) — into a {@see CollectionCriteria}, asks the related
     *    provider's
     *    {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface::fetchRelatedCollection()}
     *    to execute it (scoped to the parent), and renders a paginated
     *    {@see RelatedResponse::fromPage()} (else a plain
     *    {@see RelatedResponse::fromCollection()}) — mirroring the primary
     *    collection path. Per-relation pagination resolves
     *    `relation paginator -> related resource paginator -> server default`. A
     *    polymorphic to-many ({@see \haddowg\JsonApi\Resource\Field\MorphToMany})
     *    has no single related type — so no shared filter/sort vocabulary and no
     *    related-resource paginator — and renders its mixed-type members through a
     *    {@see PolymorphicSerializer} that resolves each member's serializer from
     *    the member object (ADR 0032).
     *
     * `?include` on the related resource flows through the same
     * {@see RelatedResponse} render path. A relation that suppresses its related
     * endpoint ({@see RelationInterface::exposesRelatedEndpoint()}) is enforced
     * here as a `404`, the route being parametric (ADR 0027).
     */
    private function fetchRelated(FetchRelatedOperation $operation): RelatedResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // The related read reaches the parent through a single fetch, so it carries the
        // parent type's read-security gate exactly as the primary read does — a
        // read-gated resource is not reachable via its related endpoints (and the
        // served document, which reports this read secured iff FetchOne is, stays
        // faithful).
        $this->gateParentRead($type, $parent, $operation->context());

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // A relation that suppresses its related endpoint (`withoutRelatedEndpoint()`)
        // is not reachable: the route stays parametric, so the handler enforces the
        // exposure flag as a `404` (reusing RelationshipNotExists) — ADR 0027.
        if (!$relation->exposesRelatedEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // A polymorphic relation (MorphTo / MorphToMany) declares several related
        // types, so there is no single related type to resolve a serializer or a
        // shared filter/sort vocabulary from up front: the serializer is resolved
        // per related object below.
        $relatedTypes = $relation->relatedTypes();
        $polymorphic = \count($relatedTypes) > 1;
        $relatedType = $relatedTypes[0] ?? $type;

        $request = $this->jsonApiRequest($operation->context());

        if ($relation->isToMany()) {
            // A polymorphic to-many's members span types, so it carries no single
            // related resource — no shared filter/sort vocabulary and no
            // related-resource paginator; only the relation's own paginator (or the
            // server default) applies, and members render through a
            // PolymorphicSerializer that resolves each member's serializer.
            $relatedResource = $polymorphic ? null : $this->types->resourceFor($server, $relatedType);
            $paginator = $this->relationCriteria->paginatorFor($relation, $relatedResource, $server);
            $window = $paginator?->window($request);

            // The related endpoint renders via CollectionDocument, which does NOT run
            // core's primary-collection `_self_` document gate — so the handler enforces
            // the relation's countable() here: `?withCount=_self_` on a non-countable
            // relation is a 400 (G21 §6b row 4), mirroring the document gate's reject.
            if ($request->countsRelationship('_self_') && !$relation->isCountable()) {
                return ErrorResponse::fromException(
                    new \haddowg\JsonApi\Exception\RelationshipCountNotAllowed(['_self_']),
                );
            }

            // The single COUNT decision for the related collection (G21): the relation's
            // paginator counts when its own withCount() author opt-in flipped it, OR the
            // client asked `?withCount=_self_` (gated above on the relation's countable()).
            // The cursor strategy is inherently count-free and resolves total-null on its
            // own branch, so it is excluded.
            $relWantsCount = $paginator !== null
                && !($paginator instanceof CursorPaginator)
                && ($paginator->wantsCount() || $request->countsRelationship('_self_'));

            $relatedProvider = $this->providers->forType($relatedType);

            // A pivot-backed belongsToMany over a pivot-aware provider (the Doctrine
            // reference) fetches the page AND the per-member pivot values in one
            // association-entity query, and renders each member's pivot values as
            // meta through a PivotMetaSerializer. The pivot field keys join the
            // recognised filter/sort vocabulary ONLY on this related endpoint, so
            // `?filter[position]`/`?sort=position` resolve (no 400) and route to the
            // pivot column; everywhere else (the primary collection, the in-memory
            // provider) a pivot key stays unrecognised (400).
            $pivot = $relatedProvider instanceof PivotAwareProviderInterface
                && $relatedProvider->supportsPivot($relatedType, $relation);

            // The related-collection endpoint resolves `?filter`/`?sort` against the
            // related resource's vocabulary *merged* with the relation's own scoped
            // filters()/sorts() — extra filters/sorts a relation declares for this ONE
            // relationship endpoint (core ADR 0051), never reachable on the primary
            // /{relatedType} collection — plus, for a pivot relation, the pivot field
            // keys. The merge + criteria assembly + the 3-tier paginator chain are
            // owned by the RelationCriteriaFactory, shared verbatim with the
            // include/linkage windowing path (bundle ADR 0057). The merged vocabulary
            // rides the CollectionCriteria, so both providers' existing handlers apply
            // it unchanged (ADR 0044), and the related resource's default order applies
            // to its sub-collection when the request sends no `sort` (a polymorphic
            // to-many has no single related resource, so no default).
            $criteria = $this->relationCriteria->criteriaFor(
                $operation->queryParameters(),
                $relatedResource,
                $relation,
                $window,
                includePivotFields: $pivot,
                wantsCount: $relWantsCount,
            );

            // The related endpoint validates a client-supplied filter value against
            // the declared constraints too — over the SAME merged vocabulary the
            // criteria carries (the related resource's filters, the relation's own
            // scoped filters, and any pivot filters), so a relation-scoped or
            // related-resource constrained filter rejects a mistyped value with the
            // same 400 (ADR 0048).
            $this->validateFilterValues($operation->queryParameters()->filter, $criteria->filters);

            if ($pivot) {
                \assert($relatedProvider instanceof PivotAwareProviderInterface);
                $pivotResult = $relatedProvider
                    ->fetchRelatedPivotCollection($relatedType, $parent, $relation, $criteria, $request);

                $serializer = new PivotMetaSerializer($server->serializerFor($relatedType), $pivotResult->pivotMap);
                $items = \is_array($pivotResult->items) ? \array_values($pivotResult->items) : \iterator_to_array($pivotResult->items, false);
                $this->preloadIncludes($relatedProvider, $items, $relatedType, $request);
                $this->applyRelationshipCounts($server, $relatedType, $items, $request);

                // Counted page: the single total fans to BOTH meta.page.total (inside
                // the count-based page) AND the universal top-level meta.total — one
                // count, two slots (G21 §6b).
                if ($paginator !== null && $pivotResult->total !== null) {
                    return RelatedResponse::fromPage(
                        $paginator->paginate($request, $items, $pivotResult->total),
                        $serializer,
                        $relation->isCountable(),
                    )->withMeta(['total' => $pivotResult->total]);
                }

                // A non-countable pivot relation's windowed fetch carries no total,
                // only `hasMore` — render a count-free page (self/first/prev/next, no
                // total/last) so the pivot endpoint honours the universal countable()
                // gate exactly as the plain path does (bundle ADR 0052).
                if ($paginator !== null && $pivotResult->windowed) {
                    return RelatedResponse::fromPage(
                        $paginator->paginateWithoutCount($request, $items, $pivotResult->hasMore),
                        $serializer,
                        $relation->isCountable(),
                    );
                }

                // Fetch-all (no paginator): the whole related set is materialized, so
                // its size is free — render meta.total UNCONDITIONALLY (G21 §5).
                return RelatedResponse::fromCollection($items, $serializer, $relation->isCountable())
                    ->withMeta(['total' => \count($items)]);
            }

            $result = $relatedProvider
                ->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);

            $serializer = $polymorphic
                ? $this->polymorphicSerializer($relation, $server)
                : $server->serializerFor($relatedType);

            // Materialize once so the related members can be both preloaded and
            // rendered. A polymorphic to-many spans types (no single related type),
            // so its includes are not batch-preloaded — it renders lazily.
            $items = \is_array($result->items) ? \array_values($result->items) : \iterator_to_array($result->items, false);
            if (!$polymorphic) {
                $this->preloadIncludes($relatedProvider, $items, $relatedType, $request);
                // ?withCount on a related collection counts the RELATED type's own
                // countable relations across the related page (bundle ADR 0052); a
                // polymorphic page spans types, so it carries no single related type.
                $this->applyRelationshipCounts($server, $relatedType, $items, $request);
            }

            // Counted page: the single total fans to BOTH meta.page.total (inside the
            // count-based page) AND the universal top-level meta.total — one count, two
            // slots (G21 §6b).
            if ($paginator !== null && $result->total !== null) {
                return RelatedResponse::fromPage(
                    $paginator->paginate($request, $items, $result->total),
                    $serializer,
                    $relation->isCountable(),
                )->withMeta(['total' => $result->total]);
            }

            // A count-free page: a non-countable relation's windowed fetch carries no
            // total, only `hasMore` — render a count-free page (self/first/prev/next,
            // no total/last) via the paginator's "do not count" mode (bundle ADR 0052).
            if ($paginator !== null && $result->windowed) {
                return RelatedResponse::fromPage(
                    $paginator->paginateWithoutCount($request, $items, $result->hasMore),
                    $serializer,
                    $relation->isCountable(),
                );
            }

            // Fetch-all (no paginator): the whole related set is materialized, so its
            // size is free — render meta.total UNCONDITIONALLY (G21 §5).
            return RelatedResponse::fromCollection($items, $serializer, $relation->isCountable())
                ->withMeta(['total' => \count($items)]);
        }

        // A to-one related endpoint has no collection, so `?withCount=_self_` is
        // invalid here — reject it up front (the to-one twin of the to-many `_self_`
        // gate above; RelatedResponse::fromResource also carries selfCountable:false so
        // core's document gate agrees).
        if ($request->countsRelationship('_self_')) {
            throw new \haddowg\JsonApi\Exception\RelationshipCountNotAllowed(['_self_']);
        }

        // Resolve the to-one serializer from the actual related object so a
        // polymorphic to-one (MorphTo) renders the object's own type. A null
        // related value has no object to discriminate, so resolveSerializer falls
        // back to the first registered serializer and the response renders
        // `data: null`.
        $related = $relation->readValue($parent, $request);

        // A polymorphic to-one (MorphTo) has no single related resource and so no shared
        // filter vocabulary — ANY requested filter key is unrecognised, exactly as the
        // polymorphic to-many path 400s through CriteriaApplier. There is no criteria to
        // run, so the offending key is surfaced directly as the same `400`
        // {@see \haddowg\JsonApi\Exception\FilterParamUnrecognized} (source.parameter
        // `filter[<key>]`). Gated on the merged requested filter being present (not on
        // `\is_object($related)`), so a filter on an empty polymorphic to-one still 400s
        // (bundle ADR 0068 follow-up #1).
        if ($polymorphic) {
            $filter = $this->toOneRequestedFilter($operation->queryParameters(), $relation, $request);
            if ($filter !== []) {
                throw new \haddowg\JsonApi\Exception\FilterParamUnrecognized(\array_key_first($filter));
            }
        }

        // A relation filter that excludes the single related object nulls the to-one,
        // so it renders `data: null` and contributes nothing — the to-one twin of the
        // to-many endpoint's filtered collection (bundle ADR 0068). The filter is the
        // operation's own `?filter` (a direct GET /{type}/{id}/{toOneRel}?filter[…])
        // merged with any relatedQuery[<rel>][filter] for this path, resolved against the
        // SAME merged vocabulary the to-many endpoint uses, validated the same (unknown
        // key / mistyped value → the endpoint's 400). Monomorphic only: a polymorphic
        // to-one has no single related resource and so no shared filter vocabulary.
        if (\is_object($related) && !$polymorphic) {
            $filter = $this->toOneRequestedFilter($operation->queryParameters(), $relation, $request);
            if ($filter !== []) {
                $relatedResource = $this->types->resourceFor($server, $relatedType);
                $criteria = $this->relationCriteria->criteriaFor(
                    new QueryParameters(fields: [], includes: [], sort: [], filter: $filter, pagination: $request->getPagination()),
                    $relatedResource,
                    $relation,
                    null,
                    includePivotFields: false,
                );
                // Validate the MERGED requested filter map (the operation's own `?filter`
                // ⊕ the relatedQuery `[filter]` already driven into the criteria), so a
                // mistyped relatedQuery filter value is the endpoint's same 400, not just
                // a mistyped `?filter` value (bundle ADR 0068 follow-up #2).
                $this->validateFilterValues($filter, $criteria->filters);

                if (!$this->providers->forType($relatedType)->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request)) {
                    $related = null;
                }
            }
        }

        $serializer = $relation->resolveSerializer($related, $server) ?? $server->serializerFor($relatedType);

        // Batch eager-load the related resource's own ?include tree (a single
        // related object is preloaded as a one-element list) so a nested include on
        // a to-one related endpoint does not N+1 (ADR 0035). A to-one related value
        // is read off the parent, so the related type may have no provider of its
        // own (it is only ever resolved through the parent) — guard the resolution.
        if (\is_object($related) && !$polymorphic && $this->providers->supportsType($relatedType)) {
            $this->preloadIncludes($this->providers->forType($relatedType), [$related], $relatedType, $request);
        }

        return RelatedResponse::fromResource($related, $serializer);
    }

    /**
     * The requested `filter[…]` for a to-one related/relationship endpoint: the
     * operation's own `?filter` (a direct `GET /{type}/{id}/{toOneRel}?filter[…]`)
     * merged with any `relatedQuery[<rel>][filter]` addressed to this relation's path
     * under the negotiated Relationship Queries profile, the relatedQuery taking
     * precedence on a key clash (the profile param is the more specific address). Empty
     * when neither is present, so the to-one renders unconditionally (the common case).
     *
     * @return array<string, mixed>
     */
    private function toOneRequestedFilter(QueryParameters $queryParameters, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        return [...$queryParameters->filter, ...$request->getRelatedQuery($relation->name())->filter];
    }

    /**
     * Validates each client-supplied `filter[<key>]` value against the value
     * constraints the matching declared filter carries, through the optional
     * {@see FilterValueValidator} (wired only when `symfony/validator` is present),
     * before the filters reach the provider. A no-op without the validator, or when
     * no requested filter declares constraints; on a violation it throws core's
     * {@see \haddowg\JsonApi\Exception\FilterValueInvalid} (`400`, `source.parameter`
     * on `filter[<key>]`), which propagates to the route-scoped exception listener.
     * Only the **raw** requested values are validated, never an author-set
     * `default()` — the default folding happens later in the provider's
     * {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier} (ADR 0048).
     *
     * The `$requested` map is the raw requested `filter[<key>]` to validate. On a
     * to-one related/relationship endpoint this is the MERGED map (the operation's
     * own `?filter` ⊕ the relatedQuery `[filter]`, the same map driven into the
     * criteria), so a mistyped relatedQuery filter value is rejected the same as a
     * mistyped `?filter` value (bundle ADR 0068 follow-up #2). All values are raw
     * client input, so an author default is still never validated.
     *
     * @param array<string, mixed>  $requested the raw requested `filter[<key>]` map
     * @param list<FilterInterface> $filters
     */
    private function validateFilterValues(array $requested, array $filters): void
    {
        $this->filterValues?->validate($requested, $filters);
    }

    /**
     * A {@see PolymorphicSerializer} that renders a polymorphic to-many's
     * mixed-type members: for each member object it resolves the serializer among
     * the relation's declared types ({@see RelationInterface::resolveSerializer()}),
     * throwing when no declared type serializes a related object.
     */
    private function polymorphicSerializer(RelationInterface $relation, Server $server): PolymorphicSerializer
    {
        return new PolymorphicSerializer(
            fn(mixed $object): SerializerInterface => $relation->resolveSerializer($object, $server)
                ?? throw new \LogicException(\sprintf('No declared type of the "%s" relationship serializes a related object.', $relation->name())),
        );
    }

    /**
     * `GET /{type}/{id}/relationships/{relationship}` — the relationship-linkage
     * document (resource identifiers only). Loads the parent, validates the
     * relationship exists, and routes the parent through the *parent* type's
     * serializer with the relationship name set so the transformer emits linkage.
     *
     * A relation that suppresses its relationship endpoint
     * ({@see RelationInterface::exposesRelationshipEndpoint()}) is enforced here as
     * a `404`, the route being parametric (ADR 0027).
     */
    private function fetchRelationship(FetchRelationshipOperation $operation): IdentifierResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // The relationship read reaches the parent through a single fetch, so it carries
        // the parent type's read-security gate exactly as the primary read does — a
        // read-gated resource is not reachable via its relationship endpoints (and the
        // served document, which reports this read secured iff FetchOne is, stays
        // faithful).
        $this->gateParentRead($type, $parent, $operation->context());

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // A relation that suppresses its relationship endpoint
        // (`withoutRelationshipEndpoint()`) is enforced handler-side as a `404`
        // because the route is parametric — ADR 0027.
        if (!$relation->exposesRelationshipEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // A relation filter that excludes a to-one's single related object nulls the
        // linkage: resolve the merged filter vocabulary, match the one related object,
        // and on a no-match write `null` onto the parent's to-one property BEFORE the
        // serializer reads linkage off it — so core emits null linkage (bundle ADR 0068).
        // A GET read, so nothing flushes. Monomorphic only (a polymorphic to-one carries
        // no shared filter vocabulary).
        if (!$relation->isToMany() && \count($relation->relatedTypes()) === 1) {
            $request = $this->jsonApiRequest($operation->context());
            $filter = $this->toOneRequestedFilter($operation->queryParameters(), $relation, $request);
            if ($filter !== []) {
                $relatedType = $relation->relatedTypes()[0];
                $related = $relation->readValue($parent, $request);
                if (\is_object($related)) {
                    $relatedResource = $this->types->resourceFor($server, $relatedType);
                    $criteria = $this->relationCriteria->criteriaFor(
                        new QueryParameters(fields: [], includes: [], sort: [], filter: $filter, pagination: $request->getPagination()),
                        $relatedResource,
                        $relation,
                        null,
                        includePivotFields: false,
                    );
                    // Validate the MERGED requested filter map (the operation's own
                    // `?filter` ⊕ the relatedQuery `[filter]`), so a mistyped relatedQuery
                    // filter value is the endpoint's same 400 (bundle ADR 0068 follow-up #2).
                    $this->validateFilterValues($filter, $criteria->filters);

                    if (!$this->providers->forType($relatedType)->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request)) {
                        Accessor::set($parent, $relation->column() ?? $relation->name(), null);
                    }
                }
            }
        } elseif (!$relation->isToMany() && \count($relation->relatedTypes()) > 1) {
            // A polymorphic to-one (MorphTo) carries no shared filter vocabulary, so ANY
            // requested filter key is unrecognised — the same `400` the polymorphic
            // to-many surfaces. Gated on the merged requested filter being present (not on
            // a target existing), so a filter on an empty polymorphic to-one still 400s
            // (bundle ADR 0068 follow-up #1).
            $request = $this->jsonApiRequest($operation->context());
            $filter = $this->toOneRequestedFilter($operation->queryParameters(), $relation, $request);
            if ($filter !== []) {
                throw new \haddowg\JsonApi\Exception\FilterParamUnrecognized(\array_key_first($filter));
            }
        }

        // A pivot-backed belongsToMany renders its per-member pivot values as
        // identifier meta here too: the relationship endpoint renders the WHOLE
        // association off the parent (no window), so the pivot-aware provider
        // supplies the full pivot map, and a PivotParentSerializer rebinds this one
        // relationship's linkage to a PivotMetaSerializer — riding core's existing
        // identifier-meta render path with no core change.
        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $relatedProvider = $this->providers->supportsType($relatedType) ? $this->providers->forType($relatedType) : null;
        if ($relatedProvider instanceof PivotAwareProviderInterface && $relatedProvider->supportsPivot($relatedType, $relation)) {
            $pivotMap = $relatedProvider->fetchRelatedPivotMap($relatedType, $parent, $relation);
            $pivotSerializer = new PivotMetaSerializer($server->serializerFor($relatedType), $pivotMap);
            $parentSerializer = new PivotParentSerializer(
                $server->serializerFor($type),
                $relationshipName,
                $relation,
                $server,
                $pivotSerializer,
            );

            return IdentifierResponse::forRelationship($parent, $parentSerializer, $relationshipName);
        }

        return IdentifierResponse::forRelationship($parent, $server->serializerFor($type), $relationshipName);
    }

    /**
     * `PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{relationship}` — the
     * relationship-mutation arms (replace / add to / remove from), one shape:
     *  1. load the parent through the read provider (a `404` when absent);
     *  2. resolve the named relation (a JSON:API `404` {@see RelationshipNotExists}
     *     when unknown);
     *  3. validate the request shape against the relation — cardinality (add/remove
     *     only on a to-many → {@see RelationshipTypeInappropriate}, `400`) and
     *     mutability flags ({@see FullReplacementProhibited}/{@see RemovalProhibited},
     *     `403`) — letting core's typed exceptions propagate to the exception
     *     listener as the right status;
     *  4. parse the linkage with core's relationship-endpoint body parser;
     *  5. apply the mutation through the persister's storage-owning relationship
     *     seam ({@see DataPersisterInterface::mutateRelationship()}), which resolves
     *     the linkage ids to the related objects/references and commits;
     *  6. render the resulting linkage ({@see IdentifierResponse::forRelationship()},
     *     `200`).
     *
     * The validation lives here (not in the persister) so core owns the request-shape
     * rules and the persister owns only the storage-correct apply — the composition
     * the Phase-3 S3 plan settled on (ADR 0017).
     */
    private function mutateRelationship(
        UpdateRelationshipOperation|AddToRelationshipOperation|RemoveFromRelationshipOperation $operation,
        JsonApiRequestInterface $body,
        Mode $mode,
    ): IdentifierResponse|ErrorResponse {
        $server = $this->server($operation->context());
        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // Add / remove are to-many operations: a POST / DELETE to a to-one
        // relationship endpoint is a cardinality error (400).
        if ($mode !== Mode::Replace && $relation->isToMany() === false) {
            throw new RelationshipTypeInappropriate($relationshipName, 'to-one', 'to-many');
        }

        $linkage = $relation->isToMany()
            ? $body->getRelationshipDataToMany($relationshipName)
            : $body->getRelationshipDataToOne($relationshipName);

        $this->guardMutability($relation, $relationshipName, $linkage, $mode, $body, $parent);

        // A linkage id at a relationship-mutation endpoint is format-validated against
        // the related type's id format exactly as the identical linkage inside a
        // whole-resource write is — so the two surfaces agree (a malformed id 422s
        // before the persister apply, not after). The relation's existing pivot rows
        // are read off the loaded parent so a pivot member already in the relationship
        // validates its MERGED pivot in the update context (a new member stays create
        // context); a Remove carries no pivot, so it reads none (ADR 0050).
        $existingPivot = $mode !== Mode::Remove && $relation instanceof BelongsToMany && $relation->pivotFields() !== []
            ? $this->providers->forType($type)->fetchRelationshipPivot($type, $parent, $relation)
            : [];
        $this->validateLinkage($relation, $linkage, $mode, $existingPivot);

        $request = $this->jsonApiRequest($operation->context());

        // The before-relationship-mutate gate (a throw aborts before the persister
        // applies the change), then the storage-correct apply, then the after hook
        // which may replace the linkage response (post-commit).
        $this->dispatch(new BeforeRelationshipMutateEvent($type, $request, $parent, $relation, $linkage, $mode, $this->serverName($request)));

        $parent = $this->persisters->forType($type)->mutateRelationship($type, $parent, $relation, $linkage, $mode);

        $response = IdentifierResponse::forRelationship($parent, $server->serializerFor($type), $relationshipName);

        $afterMutate = new AfterRelationshipMutateEvent($type, $request, $parent, $relation, $linkage, $mode, $this->serverName($request));
        $this->dispatch($afterMutate);

        return $afterMutate->response() ?? $response;
    }

    /**
     * Enforces the relation's mutability flags for the requested mutation, throwing
     * core's typed `403`s:
     *  - a to-one clear (`data: null`) is a removal — gated by `allowsRemoveFor()`;
     *    a non-null to-one `PATCH` is a replacement — gated by `allowsReplaceFor()`;
     *  - a to-many `PATCH` is a replacement — gated by `allowsReplaceFor()`; a to-many
     *    `POST` add is gated by the relation's per-relation `allowsAddFor()`
     *    endpoint-exposure flag (`cannotAdd()` → `403`, ADR 0027); a to-many
     *    `DELETE` is a removal — gated by `allowsRemoveFor()`.
     *
     * Each gate is **request-aware** (core ADR 0079): a relation the author declared
     * `cannotReplace/cannotAdd/cannotRemove(fn)` resolves its decision against the
     * inbound `$body` request and the loaded `$parent` — so "only admins may replace
     * this relationship" is enforced *here*, on the relationship-mutation endpoint
     * (without this switch the predicates would be silently ignored). An
     * unconditional `cannotX()` still bars every caller; a closure-declared one bars
     * only callers the predicate matches.
     *
     * @param ToOneRelationship|ToManyRelationship $linkage
     *
     * @throws FullReplacementProhibited
     * @throws AdditionProhibited
     * @throws RemovalProhibited
     */
    private function guardMutability(
        RelationInterface $relation,
        string $relationshipName,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        JsonApiRequestInterface $body,
        object $parent,
    ): void {
        if ($linkage instanceof ToOneRelationship) {
            if ($linkage->isEmpty()) {
                if ($relation->allowsRemoveFor($body, $parent) === false) {
                    throw new RemovalProhibited($relationshipName);
                }

                return;
            }

            if ($relation->allowsReplaceFor($body, $parent) === false) {
                throw new FullReplacementProhibited($relationshipName);
            }

            return;
        }

        if ($mode === Mode::Replace && $relation->allowsReplaceFor($body, $parent) === false) {
            throw new FullReplacementProhibited($relationshipName);
        }

        if ($mode === Mode::Add && $relation->allowsAddFor($body, $parent) === false) {
            throw new AdditionProhibited($relationshipName);
        }

        if ($mode === Mode::Remove && $relation->allowsRemoveFor($body, $parent) === false) {
            throw new RemovalProhibited($relationshipName);
        }
    }

    /**
     * Batch eager-loads the effective `?include` tree for `$entities` of `$type`
     * through the provider-agnostic {@see RelatedIncludeBatcher} before rendering — so
     * an included relationship does not N+1 against any batching provider (the Doctrine
     * reference AND the in-memory witness), one batched query per level. The batch IS
     * the include-loading mechanism (bundle ADR 0062): a relation/provider that cannot
     * batch falls back to a lazy load and the document is identical. A no-op when the
     * batcher is not wired (it is always wired in the bundle), there are no entities, or
     * there is no request to read the include tree from (ADR 0035).
     *
     * The `$provider` argument is retained for the call sites but unused: the
     * orchestrator resolves the right provider per level itself.
     *
     * @param DataProviderInterface<object> $provider
     * @param list<object>                  $entities
     */
    private function preloadIncludes(DataProviderInterface $provider, array $entities, string $type, ?JsonApiRequestInterface $request): void
    {
        if ($request === null || $entities === [] || $this->includeBatcher === null) {
            return;
        }

        $this->includeBatcher->preload($entities, $type, $request);
    }

    /**
     * Installs the per-render count seam for a read of `$type` over its fetched
     * `$items`: the {@see RelationCountBatcher} runs ONE grouped count per
     * `?withCount`-named countable relation across the whole page (no N+1), and the
     * batched map is swapped into the request-scoped holder the memoized Server
     * renders through, so core emits `meta.total` on each relationship object the
     * request named (bundle ADR 0052).
     *
     * Called on every read (single resource, collection, related collection) so it
     * also CLEARS the holder (installs `null`) when the request named no
     * `?withCount` — a prior request's counts never leak into this render. A no-op
     * when the seam is not wired (the batcher/holder are optional) or there is no
     * request to read `?withCount` from.
     *
     * @param list<object> $items the fetched page whose relationship counts to batch
     */
    private function applyRelationshipCounts(Server $server, string $type, array $items, ?JsonApiRequestInterface $request): void
    {
        if ($this->countBatcher === null || $this->relationshipCount === null) {
            return;
        }

        $this->relationshipCount->set(
            $request === null ? null : $this->countBatcher->batch($server, $type, $items, $request),
        );
    }

    /**
     * Installs the per-render relationship-window seam for a read of `$type` over
     * its fetched `$items` under the Relationship Queries profile (bundle ADR 0053):
     * the {@see RelationshipWindowBatcher} windows each rendered to-many relation to
     * page 1 of its `relatedQuery`-ordered/filtered set (writing that page back onto
     * each parent so the linkage `data` IS page 1), and the windowed map is swapped
     * into the request-scoped holder the memoized Server renders through, so core
     * emits the relationship object's pagination links in plain form.
     *
     * Called on every read so it also CLEARS the holder (installs `null`) when the
     * request did not negotiate the profile — a prior profile request's pages never
     * leak into this render. A no-op when the seam is not wired (the batcher/holder
     * are optional) or there is no request to read the profile/relatedQuery from.
     *
     * @param list<object> $items the fetched page whose rendered to-many relations to window
     */
    private function applyRelationshipWindows(Server $server, string $type, array $items, ?JsonApiRequestInterface $request): void
    {
        if ($this->windowBatcher === null || $this->relationshipPagination === null) {
            return;
        }

        $this->relationshipPagination->set(
            $request === null ? null : $this->windowBatcher->batch($server, $type, $items, $request),
        );
    }

    /**
     * Loads the parent resource of `$type` through the read provider, or `null`
     * when the id is absent or no such resource exists.
     */
    private function loadParent(string $type, ?string $id): ?object
    {
        if ($id === null) {
            return null;
        }

        return $this->providers->forType($type)->fetchOne($type, $id);
    }

    /**
     * Applies the parent type's read-security gate to a related / relationship read.
     *
     * A related (`GET /{type}/{id}/{rel}`) or relationship (`GET …/relationships/{rel}`)
     * read reaches the parent through {@see loadParent()} — the same single-resource
     * fetch the primary read gates at {@see AfterFetchOneEvent} (so a `securityRead`
     * expression denies it with a `403`). Dispatching the **same** event here closes
     * the gap where a read-gated resource was reachable via its relationship endpoints,
     * and keeps the served OpenAPI document faithful: core's projector reports these
     * related reads as secured iff `FetchOne` is, and now the runtime enforces it
     * identically (the parent IS fetched as a single resource). The response-shaping
     * read hook is not consumed here — the related/relationship path renders the
     * related value(s), not the parent — so only the security gate is observed; a
     * subscriber that replaces the response has its replacement (harmlessly) ignored
     * on this path. A no-op when no JSON:API request is available (a programmatic
     * dispatch) or no dispatcher is wired.
     */
    private function gateParentRead(string $type, object $parent, OperationContext $context): void
    {
        $request = $context->httpRequest();
        if (!$request instanceof JsonApiRequestInterface) {
            return;
        }

        $this->dispatch(new AfterFetchOneEvent($type, $request, $parent, $this->serverName($request)));
    }

    /**
     * Resolves the declared, non-hidden relation named `$name` on `$type`'s
     * resource, or `null` when the type is a bare pair (no field inventory) or has
     * no such relationship — the handler maps a `null` to a JSON:API `404`.
     */
    private function resolveRelation(Server $server, string $type, string $name): ?RelationInterface
    {
        return $this->types->relationNamed($server, $type, $name);
    }

    /**
     * The current JSON:API request from the operation context, for the relation's
     * value reader (a custom `extractUsing()` extractor may consult it).
     */
    private function jsonApiRequest(OperationContext $context): JsonApiRequestInterface
    {
        $request = $context->httpRequest();
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    private function create(CreateResourceOperation $operation): DataResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $body = $operation->body();
        $request = $this->jsonApiRequest($operation->context());

        $this->validate($server, $type, $body, creating: true);

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);

        // Embedded relationships are stripped from the body so core hydrates only
        // attributes + id; the persister then sets the associations (resolving
        // linkage ids → managed references / stored objects) so a typed entity
        // never has a scalar id assigned to an association property (ADR 0018).
        $relationships = $this->extractRelationships($server, $type, $body, creating: true);

        $entity = $persister->instantiate($type);

        // Apply the embedded associations BEFORE core hydrates, so a flattened `on()`
        // attribute (which hydrates AFTER relationships and reads its owner off the
        // parent) sees a related model associated in the SAME request body (ADR 0085).
        // Relationships are still stripped from the hydrate body, so core's
        // hydrateRelationships() stays a no-op (no scalar-id assignment, ADR 0018).
        $this->applyRelationships($persister, $type, $entity, $relationships, $body, creating: true);

        $entity = $server->hydratorFor($type)->hydrate($this->withoutRelationships($body), $entity);
        \assert(\is_object($entity));

        $this->validateEntity($server, $type, $entity, creating: true);

        // Before-save then before-create gates: a subscriber/hook may mutate the
        // entity (persisted by the flush below) or throw to abort (the throw
        // propagates to the ExceptionListener, so the persister never runs and
        // nothing commits).
        $this->dispatch(new BeforeSaveEvent($type, $request, $entity, true, $this->serverName($request)));
        $this->dispatch(new BeforeCreateEvent($type, $request, $entity, $this->serverName($request)));

        $entity = $persister->create($type, $entity);

        // A write response honours ?include too — it renders the same DataResponse
        // as a fetch — so batch-preload the created resource's effective include
        // tree, keeping a nested include off the N+1 path (ADR 0035).
        $this->preloadIncludes($this->providers->forType($type), [$entity], $type, $request);

        // The Location uses the resource's URI segment (its uriType), so it matches
        // the route the client will GET (ADR 0022); a bare pair has no resource, so
        // it falls back to the type.
        $uriType = $this->types->resourceFor($server, $type)?->uriType() ?? $type;

        $response = DataResponse::fromResource($entity, $serializer)
            ->withStatus(201)
            ->withHeader('Location', $server->baseUri() . '/' . $uriType . '/' . $serializer->getId($entity));

        // After-create then after-save hooks (post-commit) may replace the 201;
        // after-save fires last, so it has the final word.
        $afterCreate = new AfterCreateEvent($type, $request, $entity, $this->serverName($request));
        $this->dispatch($afterCreate);
        $response = $afterCreate->response() ?? $response;

        $afterSave = new AfterSaveEvent($type, $request, $entity, true, $this->serverName($request));
        $this->dispatch($afterSave);

        return $afterSave->response() ?? $response;
    }

    private function update(UpdateResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $id = $operation->target()->id;
        $body = $operation->body();
        $request = $this->jsonApiRequest($operation->context());

        $provider = $this->providers->forType($type);
        $entity = $id !== null ? $provider->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // A shallow clone of the loaded target taken before hydration, so a
        // before-update hook can diff the incoming change against the prior state.
        $original = clone $entity;

        // The already-loaded target is passed to the validator so a PATCH
        // validates the MERGED resource state (stored values overlaid by the
        // incoming partial), not the partial alone — so a cross-field/conditional
        // rule that depends on a stored sibling absent from the body evaluates
        // correctly, and a required-on-update field present in stored state but
        // not re-sent does not spuriously 422 (ADR 0049).
        $this->validate($server, $type, $body, creating: false, existingObject: $entity);

        $serializer = $server->serializerFor($type);
        $persister = $this->persisters->forType($type);

        // As for create: replace the associations named in `data.relationships`
        // through the persister seam (ADR 0018) BEFORE core hydrates, so a flattened
        // `on()` attribute (hydrated AFTER relationships, reading its owner off the
        // parent) writes onto a related model switched in the SAME request body — not
        // the previously-associated owner (ADR 0085). Relationships are still stripped
        // from the hydrate body, so core's hydrateRelationships() stays a no-op.
        $relationships = $this->extractRelationships($server, $type, $body, creating: false);

        $this->applyRelationships($persister, $type, $entity, $relationships, $body, creating: false);

        $entity = $server->hydratorFor($type)->hydrate($this->withoutRelationships($body), $entity);
        \assert(\is_object($entity));

        $this->validateEntity($server, $type, $entity, creating: false);

        // Before-save then before-update gates (the entity is mutable, `$original`
        // is the pre-change snapshot): a throw aborts before the persister commits.
        $this->dispatch(new BeforeSaveEvent($type, $request, $entity, false, $this->serverName($request)));
        $this->dispatch(new BeforeUpdateEvent($type, $request, $entity, $original, $this->serverName($request)));

        $entity = $persister->update($type, $entity);

        // A PATCH response honours ?include as a fetch does — preload the updated
        // resource's effective include tree (ADR 0035).
        $this->preloadIncludes($provider, [$entity], $type, $request);

        $response = DataResponse::fromResource($entity, $serializer);

        // After-update then after-save hooks (post-commit) may replace the 200;
        // after-save fires last, so it has the final word.
        $afterUpdate = new AfterUpdateEvent($type, $request, $entity, $this->serverName($request));
        $this->dispatch($afterUpdate);
        $response = $afterUpdate->response() ?? $response;

        $afterSave = new AfterSaveEvent($type, $request, $entity, false, $this->serverName($request));
        $this->dispatch($afterSave);

        return $afterSave->response() ?? $response;
    }

    /**
     * Collects the writable relationships present in the write body, each paired
     * with its parsed linkage, so the handler can apply them through the persister
     * seam after core hydrates the attributes. Each named relationship is resolved
     * through the dual-source {@see TypeMetadataResolver::relationNamed()} — a
     * resource's own relations or a resource-less type's standalone relations (ADR
     * 0026) — so a type with no resource can still have its relationships set on a
     * whole-resource write. A relationship that is unknown, or read-only for this
     * operation, is skipped — the read-only gate mirrors core's
     * `AbstractResource::hydrateRelationships()`, which never sees these because the
     * handler strips relationships from the body before core hydrates, so the gate
     * is reapplied here.
     *
     * @return list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>
     */
    private function extractRelationships(Server $server, string $type, JsonApiRequestInterface $body, bool $creating): array
    {
        $collected = [];
        foreach ($this->bodyRelationshipNames($body) as $name) {
            $relation = $this->types->relationNamed($server, $type, $name);
            if ($relation === null) {
                continue;
            }

            // Reapply core's read-only relationship gate: a relation the author marked
            // readOnly() must not be writable through a whole-resource body. The gate
            // is request-aware (core ADR 0079) — a `readOnly(fn)` relation is writable
            // for a caller it is *not* read-only for — and consults the inbound
            // request, mirroring core's own request-aware `hydrateRelationships()`.
            if ($relation->isReadOnlyFor($creating, $body)) {
                continue;
            }

            if ($relation->isToMany()) {
                if ($body->hasToManyRelationship($name)) {
                    $collected[] = ['relation' => $relation, 'linkage' => $body->getToManyRelationship($name)];
                }

                continue;
            }

            if ($body->hasToOneRelationship($name)) {
                $collected[] = ['relation' => $relation, 'linkage' => $body->getToOneRelationship($name)];
            }
        }

        return $collected;
    }

    /**
     * Applies each collected relationship to the hydrated entity through the
     * persister's relationship seam in {@see Mode::Replace} — the persister resolves
     * the linkage ids to the related references/objects and sets the association —
     * deferring the commit (`$flush = false`) so the subsequent
     * {@see DataPersisterInterface::create()}/{@see DataPersisterInterface::update()}
     * owns the single flush and a not-yet-persisted create target is never flushed
     * mid-association (ADR 0018).
     *
     * An embedded relationship in a whole-resource write is a FULL replacement of the
     * named association (never an incremental add/remove), so on an UPDATE each one is
     * gated by {@see guardMutability()} in {@see Mode::Replace} — the same gate the
     * dedicated `/relationships/{rel}` endpoint applies — before the persister runs, so
     * a `cannotReplace(fn)` relation embedded in a `PATCH` raises core's typed `403`
     * exactly as a `PATCH …/relationships/{rel}` would (an empty to-one linkage maps to
     * the `allowsRemove*` check inside the gate, so `Mode::Replace` is correct for both
     * cardinalities). The gate is **skipped on a CREATE**: the `cannot*` gates govern
     * mutation of an EXISTING relationship, but a create sets the initial state (there
     * is nothing to replace), and gating it would make a `cannotReplace` relation
     * impossible to ever set, since such a relation also has no relationship endpoint.
     *
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     */
    private function applyRelationships(
        DataPersisterInterface $persister,
        string $type,
        object $entity,
        array $relationships,
        JsonApiRequestInterface $body,
        bool $creating,
    ): void {
        foreach ($relationships as $relationship) {
            if ($creating === false) {
                $this->guardMutability(
                    $relationship['relation'],
                    $relationship['relation']->name(),
                    $relationship['linkage'],
                    Mode::Replace,
                    $body,
                    $entity,
                );
            }

            $persister->mutateRelationship(
                $type,
                $entity,
                $relationship['relation'],
                $relationship['linkage'],
                Mode::Replace,
                flush: false,
            );
        }
    }

    /**
     * The relationship names present in the write body's `data.relationships`
     * member, or an empty list when the document carries none.
     *
     * @return list<string>
     */
    private function bodyRelationshipNames(JsonApiRequestInterface $body): array
    {
        $data = $body->getResource();
        if (!\is_array($data)) {
            return [];
        }

        $relationships = $data['relationships'] ?? null;
        if (!\is_array($relationships)) {
            return [];
        }

        /** @var list<string> $names */
        $names = \array_values(\array_filter(\array_keys($relationships), '\is_string'));

        return $names;
    }

    /**
     * A copy of the write body with `data.relationships` removed, so core's
     * per-type hydrator hydrates only the id + attributes and never assigns a
     * scalar linkage id to a typed association property — the bundle applies the
     * associations through the persister seam instead (ADR 0018).
     */
    private function withoutRelationships(JsonApiRequestInterface $body): JsonApiRequestInterface
    {
        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if (!\is_array($data) || !isset($data['relationships'])) {
            return $body;
        }

        unset($data['relationships']);
        $document['data'] = $data;

        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return $stripped;
    }

    private function delete(DeleteResourceOperation $operation): DataResponse|NoContentResponse|ErrorResponse
    {
        $type = $operation->target()->type;
        $id = $operation->target()->id;
        $request = $this->jsonApiRequest($operation->context());

        $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // The before-delete gate (a throw aborts before the persister deletes — a
        // delete guard's natural seam, e.g. a 409 when the resource is referenced).
        $this->dispatch(new BeforeDeleteEvent($type, $request, $entity, $this->serverName($request)));

        $this->persisters->forType($type)->delete($type, $entity);

        $response = NoContentResponse::create();

        // The after-delete hook (post-commit) may replace the 204 — e.g. a
        // soft-delete that renders the now-flagged resource instead.
        $afterDelete = new AfterDeleteEvent($type, $request, $entity, $this->serverName($request));
        $this->dispatch($afterDelete);

        return $afterDelete->response() ?? $response;
    }

    /**
     * Runs the Symfony Validator bridge over the request document, when one is
     * wired (it is optional — `symfony/validator` is a `suggest` dependency). A
     * bare serializer/hydrator pair declares no constraints, so there is nothing
     * to validate. On an update the already-loaded `$existingObject` is forwarded
     * so the bridge validates the MERGED resource state (stored values overlaid by
     * the incoming partial) rather than the partial alone (ADR 0049); on create it
     * is null.
     */
    private function validate(Server $server, string $type, JsonApiRequestInterface $body, bool $creating, ?object $existingObject = null): void
    {
        if ($this->validator === null) {
            return;
        }

        $resource = $this->types->resourceFor($server, $type);
        if ($resource === null) {
            return;
        }

        // On an update, read each pivot relation's existing rows so the validator can
        // fold them under the incoming linkage meta per member (an existing member
        // validates the merged pivot in the update context, a new member the incoming
        // meta in create context). On create there is no parent, so the map is empty —
        // every member is new (ADR 0050).
        $existingPivots = $existingObject !== null
            ? $this->existingPivots($server, $type, $body, $existingObject)
            : [];

        $this->validator->validate($resource, $body, $creating, $existingObject, $existingPivots);
    }

    /**
     * The existing pivot rows of each `belongsToMany` pivot relation present in the
     * write body, keyed by relation name then by related id — read through the read
     * provider's {@see DataProviderInterface::fetchRelationshipPivot()} seam off the
     * already-loaded parent. Only pivot relations carried in the body are read; a
     * non-pivot relation (or a provider that stores no pivot) returns `[]` and is
     * skipped. The validator uses this to validate an existing member's MERGED pivot in
     * the update context (ADR 0050).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function existingPivots(Server $server, string $type, JsonApiRequestInterface $body, object $parent): array
    {
        $provider = $this->providers->forType($type);

        $existingPivots = [];
        foreach ($this->bodyRelationshipNames($body) as $name) {
            $relation = $this->types->relationNamed($server, $type, $name);
            if (!$relation instanceof BelongsToMany || $relation->pivotFields() === []) {
                continue;
            }

            $pivot = $provider->fetchRelationshipPivot($type, $parent, $relation);
            if ($pivot !== []) {
                $existingPivots[$name] = $pivot;
            }
        }

        return $existingPivots;
    }

    /**
     * Format-validates a relationship-mutation endpoint's parsed linkage against the
     * related type's id format through the validator bridge, when one is wired (it is
     * optional). A no-op without the validator. The {@see Mode} and the relation's
     * existing pivot rows are forwarded so a pivot add/replace validates each member's
     * pivot `meta` in the per-member new/existing context — a member already in the
     * relationship merges its stored pivot row under the incoming meta and validates in
     * the update context (a writable field absent from meta keeps its stored value),
     * while a genuinely-new member validates the incoming meta in the create (new-row)
     * context (a required writable pivot field absent on it is a `422` before persist,
     * never a DB NOT-NULL `500`) (ADR 0050).
     *
     * @param ToOneRelationship|ToManyRelationship $linkage
     * @param array<string, array<string, mixed>>  $existingPivot the relation's existing pivot rows, by related id
     */
    private function validateLinkage(RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, array $existingPivot = []): void
    {
        $this->validator?->validateRelationshipLinkage($relation, $linkage, $mode, $existingPivot);
    }

    /**
     * Runs the bridge's entity-level pass over the hydrated entity (uniqueness and
     * other {@see \haddowg\JsonApiBundle\Validation\EntityConstraintInterface} rules
     * that need the persisted object). A no-op without the optional validator, or
     * for a resource that declares no entity-level constraint.
     */
    private function validateEntity(Server $server, string $type, object $entity, bool $creating): void
    {
        if ($this->validator === null) {
            return;
        }

        $resource = $this->types->resourceFor($server, $type);
        if ($resource === null) {
            return;
        }

        $this->validator->validateEntity($resource, $entity, $creating);
    }

    private function server(OperationContext $context): Server
    {
        $server = $context->server;
        \assert($server instanceof Server);

        return $server;
    }

    /**
     * Dispatches a lifecycle event through the injected Symfony dispatcher — a
     * no-op when no dispatcher is wired (the events are an opt-in seam, off when
     * symfony/event-dispatcher is absent). A before-event subscriber that throws a
     * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} propagates out of
     * here to the route-scoped exception listener; an after-event subscriber's
     * replaced response is read back off the event by the caller.
     */
    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    /**
     * The name of the server the request dispatched on, read from the
     * `_jsonapi_server` request attribute the {@see \haddowg\JsonApiBundle\EventListener\RequestListener}
     * carries through (the route default Symfony's PsrHttpFactory copies onto the
     * PSR request), defaulting to the implicit `default` server. It is passed on
     * each event so a subscriber can resolve the right server in a multi-server app.
     */
    private function serverName(JsonApiRequestInterface $request): string
    {
        $name = $request->getAttribute('_jsonapi_server');

        return \is_string($name) && $name !== '' ? $name : ServerProvider::DEFAULT_SERVER;
    }
}
