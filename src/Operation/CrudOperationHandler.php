<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

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
    ) {}

    public function handle(\haddowg\JsonApi\Operation\JsonApiOperationInterface $operation): DataResponse|NoContentResponse|ErrorResponse
    {
        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
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

    private function create(CreateResourceOperation $operation): DataResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);

        $entity = $server->hydratorFor($type)->hydrate($operation->body(), $persister->instantiate($type));
        \assert(\is_object($entity));

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

        $serializer = $server->serializerFor($type);

        $entity = $server->hydratorFor($type)->hydrate($operation->body(), $entity);
        \assert(\is_object($entity));

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

    private function server(OperationContext $context): Server
    {
        $server = $context->server;
        \assert($server instanceof Server);

        return $server;
    }
}
