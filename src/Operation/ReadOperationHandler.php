<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * The thin generic read handler the {@see \haddowg\JsonApiBundle\Server\ServerFactory}
 * wires via `Server::withHandler()`, so `Server::dispatch($operation)` has a
 * target.
 *
 * It handles {@see FetchResourceOperation}, branching on whether the target
 * carries an id. A single fetch (`fetchOne`) maps a missing resource to a `404`
 * {@see ResourceNotFound}. A collection fetch resolves what the resource
 * declares — `filters()`, `allSorts()`, `pagination()` (falling back to the
 * server's default paginator) — into a {@see CollectionCriteria}, asks the
 * per-type {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface}
 * resolved from the {@see DataProviderRegistry} to execute it, and renders a
 * paginated {@see DataResponse::fromPage()} (pagination links + `meta.page`)
 * when a paginator windowed the fetch, else a plain collection.
 *
 * Unknown `filter[…]`/`sort` keys raise core's 400 exceptions out of the
 * provider; they propagate to the route-scoped `kernel.exception` listener,
 * which owns all error rendering on JSON:API routes. Any non-read operation
 * (writes arrive in a later phase) renders a `404` placeholder. The handler
 * stays PSR-7-free except for the paginator's request access — pagination
 * derives its window and page links from the originating request, so an
 * operation dispatched programmatically (no HTTP message) is never paginated.
 */
final class ReadOperationHandler implements \haddowg\JsonApi\Operation\OperationHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(\haddowg\JsonApi\Operation\JsonApiOperationInterface $operation): DataResponse|ErrorResponse
    {
        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    private function fetch(FetchResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

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
}
