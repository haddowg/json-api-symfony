<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

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
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\SupportsSingular;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\PreloadsIncludesInterface;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use haddowg\JsonApiBundle\Validation\ResourceValidator;

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
        private readonly ?ResourceValidator $validator = null,
    ) {}

    public function handle(\haddowg\JsonApi\Operation\JsonApiOperationInterface $operation): DataResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse
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

            return DataResponse::fromResource($model, $serializer);
        }

        // A bare serializer/hydrator pair declares no field inventory, so it has
        // no filter/sort vocabulary and no resource-level paginator.
        $resource = $this->types->resourceFor($server, $type);

        $filters = $resource?->filters() ?? [];

        // A singular filter the client applied collapses the collection to a
        // zero-to-one response — a single resource (the first match) or null,
        // never an array, and never paginated (core ADR 0039).
        $singular = $this->appliesSingularFilter($filters, $operation->queryParameters());

        $paginator = $singular ? null : ($resource?->pagination() ?? $server->defaultPaginator());
        $window = $paginator !== null && $request !== null ? $paginator->window($request) : null;

        $result = $provider->fetchCollection($type, new CollectionCriteria(
            $operation->queryParameters(),
            $filters,
            $resource?->allSorts() ?? [],
            $window,
            // Applied only when the request carries no `sort` (core ADR 0044); a
            // bare serializer/hydrator pair has no resource and so no default.
            $resource?->defaultSort() ?? [],
        ));

        // Materialize once so the items can be both preloaded and rendered (and a
        // singular filter can peek the first without consuming a one-shot iterator).
        $items = \is_array($result->items) ? \array_values($result->items) : \iterator_to_array($result->items, false);

        if ($singular) {
            $first = $items[0] ?? null;
            if ($first !== null) {
                $this->preloadIncludes($provider, [$first], $type, $request);
            }

            return DataResponse::fromResource($first, $serializer);
        }

        // Batch eager-load the effective ?include tree across the whole page/collection
        // so includes do not N+1 (ADR 0035).
        $this->preloadIncludes($provider, $items, $type, $request);

        if ($paginator !== null && $request !== null && $result->total !== null) {
            return DataResponse::fromPage($paginator->paginate($request, $items, $result->total), $serializer);
        }

        return DataResponse::fromCollection($items, $serializer);
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
     *  - a to-many resolves the *related* type's filter/sort/pagination vocabulary
     *    into a {@see CollectionCriteria}, asks the related provider's
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
            $paginator = $relation->pagination()
                ?? $relatedResource?->pagination()
                ?? $server->defaultPaginator();
            $window = $paginator?->window($request);

            $criteria = new CollectionCriteria(
                $operation->queryParameters(),
                $relatedResource?->filters() ?? [],
                $relatedResource?->allSorts() ?? [],
                $window,
                // The related resource's default order applies to its related
                // sub-collection too when the request sends no `sort` (core ADR
                // 0044); a polymorphic to-many has no single related resource.
                $relatedResource?->defaultSort() ?? [],
            );

            $relatedProvider = $this->providers->forType($relatedType);
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
            }

            if ($paginator !== null && $result->total !== null) {
                return RelatedResponse::fromPage(
                    $paginator->paginate($request, $items, $result->total),
                    $serializer,
                );
            }

            return RelatedResponse::fromCollection($items, $serializer);
        }

        // Resolve the to-one serializer from the actual related object so a
        // polymorphic to-one (MorphTo) renders the object's own type. A null
        // related value has no object to discriminate, so resolveSerializer falls
        // back to the first registered serializer and the response renders
        // `data: null`.
        $related = $relation->readValue($parent, $request);
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
            ? $body->getRelationshipLinkageToMany($relationshipName)
            : $body->getRelationshipLinkageToOne($relationshipName);

        $this->guardMutability($relation, $relationshipName, $linkage, $mode);

        // A linkage id at a relationship-mutation endpoint is format-validated against
        // the related type's id format exactly as the identical linkage inside a
        // whole-resource write is — so the two surfaces agree (a malformed id 422s
        // before the persister apply, not after).
        $this->validateLinkage($relation, $linkage);

        $parent = $this->persisters->forType($type)->mutateRelationship($type, $parent, $relation, $linkage, $mode);

        return IdentifierResponse::forRelationship($parent, $server->serializerFor($type), $relationshipName);
    }

    /**
     * Enforces the relation's mutability flags for the requested mutation, throwing
     * core's typed `403`s:
     *  - a to-one clear (`data: null`) is a removal — gated by `allowsRemove()`;
     *    a non-null to-one `PATCH` is a replacement — gated by `allowsReplace()`;
     *  - a to-many `PATCH` is a replacement — gated by `allowsReplace()`; a to-many
     *    `POST` add is gated by the relation's per-relation `allowsAdd()`
     *    endpoint-exposure flag (`cannotAdd()` → `403`, ADR 0027); a to-many
     *    `DELETE` is a removal — gated by `allowsRemove()`.
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
    ): void {
        if ($linkage instanceof ToOneRelationship) {
            if ($linkage->isEmpty()) {
                if ($relation->allowsRemove() === false) {
                    throw new RemovalProhibited($relationshipName);
                }

                return;
            }

            if ($relation->allowsReplace() === false) {
                throw new FullReplacementProhibited($relationshipName);
            }

            return;
        }

        if ($mode === Mode::Replace && $relation->allowsReplace() === false) {
            throw new FullReplacementProhibited($relationshipName);
        }

        if ($mode === Mode::Add && $relation->allowsAdd() === false) {
            throw new AdditionProhibited($relationshipName);
        }

        if ($mode === Mode::Remove && $relation->allowsRemove() === false) {
            throw new RemovalProhibited($relationshipName);
        }
    }

    /**
     * Batch eager-loads the effective `?include` tree for `$entities` of `$type`
     * through the provider's optional {@see PreloadsIncludesInterface} capability,
     * before rendering — so an included relationship does not N+1 against a provider
     * that opts in (the Doctrine reference does, when the preloader library is
     * installed). A no-op when the provider does not implement the capability, or
     * when there is no request to read the include tree from (ADR 0035).
     *
     * @param DataProviderInterface<object> $provider
     * @param list<object>                  $entities
     */
    private function preloadIncludes(DataProviderInterface $provider, array $entities, string $type, ?JsonApiRequestInterface $request): void
    {
        if ($request === null || $entities === [] || !$provider instanceof PreloadsIncludesInterface) {
            return;
        }

        $provider->preloadIncludes($entities, $type, $request);
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

        $this->validate($server, $type, $body, creating: true);

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);

        // Embedded relationships are stripped from the body so core hydrates only
        // attributes + id; the persister then sets the associations (resolving
        // linkage ids → managed references / stored objects) so a typed entity
        // never has a scalar id assigned to an association property (ADR 0018).
        $relationships = $this->extractRelationships($server, $type, $body, creating: true);

        $entity = $server->hydratorFor($type)->hydrate($this->withoutRelationships($body), $persister->instantiate($type));
        \assert(\is_object($entity));

        $this->applyRelationships($persister, $type, $entity, $relationships);

        $this->validateEntity($server, $type, $entity, creating: true);

        $entity = $persister->create($type, $entity);

        // A write response honours ?include too — it renders the same DataResponse
        // as a fetch — so batch-preload the created resource's effective include
        // tree, keeping a nested include off the N+1 path (ADR 0035).
        $request = $operation->context()->httpRequest();
        $this->preloadIncludes(
            $this->providers->forType($type),
            [$entity],
            $type,
            $request instanceof JsonApiRequestInterface ? $request : null,
        );

        // The Location uses the resource's URI segment (its uriType), so it matches
        // the route the client will GET (ADR 0022); a bare pair has no resource, so
        // it falls back to the type.
        $uriType = $this->types->resourceFor($server, $type)?->uriType() ?? $type;

        return DataResponse::fromResource($entity, $serializer)
            ->withStatus(201)
            ->withHeader('Location', $server->baseUri() . '/' . $uriType . '/' . $serializer->getId($entity));
    }

    private function update(UpdateResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $id = $operation->target()->id;
        $body = $operation->body();

        $provider = $this->providers->forType($type);
        $entity = $id !== null ? $provider->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $this->validate($server, $type, $body, creating: false);

        $serializer = $server->serializerFor($type);
        $persister = $this->persisters->forType($type);

        // As for create: hydrate attributes via core, then replace the associations
        // named in `data.relationships` through the persister seam (ADR 0018).
        $relationships = $this->extractRelationships($server, $type, $body, creating: false);

        $entity = $server->hydratorFor($type)->hydrate($this->withoutRelationships($body), $entity);
        \assert(\is_object($entity));

        $this->applyRelationships($persister, $type, $entity, $relationships);

        $this->validateEntity($server, $type, $entity, creating: false);

        $entity = $persister->update($type, $entity);

        // A PATCH response honours ?include as a fetch does — preload the updated
        // resource's effective include tree (ADR 0035).
        $request = $operation->context()->httpRequest();
        $this->preloadIncludes(
            $provider,
            [$entity],
            $type,
            $request instanceof JsonApiRequestInterface ? $request : null,
        );

        return DataResponse::fromResource($entity, $serializer);
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
            // readOnly() must not be writable through a whole-resource body.
            if ($relation->isReadOnly($creating)) {
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
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     */
    private function applyRelationships(
        DataPersisterInterface $persister,
        string $type,
        object $entity,
        array $relationships,
    ): void {
        foreach ($relationships as $relationship) {
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

    private function delete(DeleteResourceOperation $operation): NoContentResponse|ErrorResponse
    {
        $type = $operation->target()->type;
        $id = $operation->target()->id;

        $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $this->persisters->forType($type)->delete($type, $entity);

        return NoContentResponse::create();
    }

    /**
     * Runs the Symfony Validator bridge over the request document, when one is
     * wired (it is optional — `symfony/validator` is a `suggest` dependency). A
     * bare serializer/hydrator pair declares no constraints, so there is nothing
     * to validate.
     */
    private function validate(Server $server, string $type, JsonApiRequestInterface $body, bool $creating): void
    {
        if ($this->validator === null) {
            return;
        }

        $resource = $this->types->resourceFor($server, $type);
        if ($resource === null) {
            return;
        }

        $this->validator->validate($resource, $body, $creating);
    }

    /**
     * Format-validates a relationship-mutation endpoint's parsed linkage against the
     * related type's id format through the validator bridge, when one is wired (it is
     * optional). A no-op without the validator.
     *
     * @param ToOneRelationship|ToManyRelationship $linkage
     */
    private function validateLinkage(RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage): void
    {
        $this->validator?->validateRelationshipLinkage($relation, $linkage);
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
}
