<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
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
 * Relationship reads share the same provider-resolution shape: load the parent
 * through the read provider (a `404` when absent), resolve the named relation on
 * the parent resource via core's {@see \haddowg\JsonApi\Resource\AbstractResource::relationNamed()}
 * (a JSON:API `404` {@see RelationshipNotExists} when the relationship is unknown),
 * then render —
 *  - {@see FetchRelatedOperation} reads the related domain value(s) off the parent
 *    without serializing ({@see RelationInterface::readValue()}) and serializes
 *    them through the *related* type's serializer, as a single resource
 *    ({@see RelatedResponse::fromResource()}, `data:null` for an empty to-one) or a
 *    collection ({@see RelatedResponse::fromCollection()}) per cardinality;
 *  - {@see FetchRelationshipOperation} routes the parent through the *parent*
 *    type's serializer with the relationship name set, emitting linkage only
 *    ({@see IdentifierResponse::forRelationship()}).
 *
 * Core's typed exceptions (unknown filter/sort keys, hydration failures, the
 * validator bridge's `422`) propagate to the route-scoped `kernel.exception`
 * listener, which owns all error rendering on JSON:API routes. The generic
 * zero-handler CRUD engine is a later phase; this proves the lifecycle over the
 * SPIs first.
 */
final class CrudOperationHandler implements \haddowg\JsonApi\Operation\OperationHandlerInterface
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
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
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    private function fetch(FetchResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $provider = $this->providers->forType($type);
        $serializer = $server->serializerFor($type);

        $id = $operation->target()->id;
        if ($id !== null) {
            $model = $provider->fetchOne($type, $id);
            if ($model === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            return DataResponse::fromResource($model, $serializer);
        }

        try {
            $resource = $server->resources()->resourceFor($type);
        } catch (NoResourceRegistered) {
            // A bare serializer/hydrator pair declares no field inventory, so
            // it has no filter/sort vocabulary and no resource-level paginator.
            $resource = null;
        }

        $request = $operation->context()->httpRequest();
        $request = $request instanceof JsonApiRequestInterface ? $request : null;

        $paginator = $resource?->pagination() ?? $server->defaultPaginator();
        $window = $paginator !== null && $request !== null ? $paginator->window($request) : null;

        $result = $provider->fetchCollection($type, new CollectionCriteria(
            $operation->queryParameters(),
            $resource?->filters() ?? [],
            $resource?->allSorts() ?? [],
            $window,
        ));

        if ($paginator !== null && $request !== null && $result->total !== null) {
            return DataResponse::fromPage($paginator->paginate($request, $result->items, $result->total), $serializer);
        }

        return DataResponse::fromCollection($result->items, $serializer);
    }

    /**
     * `GET /{type}/{id}/{relationship}` — the related-resource(s) document. Loads
     * the parent, resolves the named relation, reads the related domain value(s)
     * off the parent without serializing, and renders them through the related
     * type's serializer: a single resource (`data:null` for an empty to-one) or a
     * collection per the relation's cardinality. `?include` on the related
     * resource flows through the same {@see RelatedResponse} render path.
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

        // Polymorphic MorphTo related-resource endpoints (a single relation that
        // yields several types) need per-item serializer resolution; deferred to a
        // later slice. The declared related types are monomorphic here.
        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $relatedSerializer = $server->serializerFor($relatedType);

        $request = $this->jsonApiRequest($operation->context());
        $related = $relation->readValue($parent, $request);

        if ($relation->isToMany()) {
            \assert(\is_iterable($related));

            return RelatedResponse::fromCollection($parent, $relationshipName, $related, $relatedSerializer);
        }

        return RelatedResponse::fromResource($parent, $relationshipName, $related, $relatedSerializer);
    }

    /**
     * `GET /{type}/{id}/relationships/{relationship}` — the relationship-linkage
     * document (resource identifiers only). Loads the parent, validates the
     * relationship exists, and routes the parent through the *parent* type's
     * serializer with the relationship name set so the transformer emits linkage.
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

        if ($this->resolveRelation($server, $type, $relationshipName) === null) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        return IdentifierResponse::forRelationship($parent, $server->serializerFor($type), $relationshipName);
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
     * resource, or `null` when the resource is bare (no field inventory) or has no
     * such relationship — the handler maps a `null` to a JSON:API `404`.
     */
    private function resolveRelation(Server $server, string $type, string $name): ?RelationInterface
    {
        try {
            $resource = $server->resources()->resourceFor($type);
        } catch (NoResourceRegistered) {
            return null;
        }

        return $resource->relationNamed($name);
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

        $this->validate($server, $type, $operation->body(), creating: true);

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);

        $entity = $server->hydratorFor($type)->hydrate($operation->body(), $persister->instantiate($type));
        \assert(\is_object($entity));

        $this->validateEntity($server, $type, $entity, creating: true);

        $entity = $persister->create($type, $entity);

        return DataResponse::fromResource($entity, $serializer)
            ->withStatus(201)
            ->withHeader('Location', $server->baseUri() . '/' . $type . '/' . $serializer->getId($entity));
    }

    private function update(UpdateResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $id = $operation->target()->id;

        $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $this->validate($server, $type, $operation->body(), creating: false);

        $serializer = $server->serializerFor($type);

        $entity = $server->hydratorFor($type)->hydrate($operation->body(), $entity);
        \assert(\is_object($entity));

        $this->validateEntity($server, $type, $entity, creating: false);

        $entity = $this->persisters->forType($type)->update($type, $entity);

        return DataResponse::fromResource($entity, $serializer);
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

        try {
            $resource = $server->resources()->resourceFor($type);
        } catch (NoResourceRegistered) {
            return;
        }

        $this->validator->validate($resource, $body, $creating);
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

        try {
            $resource = $server->resources()->resourceFor($type);
        } catch (NoResourceRegistered) {
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
