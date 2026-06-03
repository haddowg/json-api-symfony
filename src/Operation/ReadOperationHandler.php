<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * The thin generic read handler the {@see \haddowg\JsonApiBundle\Server\ServerFactory}
 * wires via `Server::withHandler()`, so `Server::dispatch($operation)` has a
 * target.
 *
 * Phase-0 read slice only: it handles {@see FetchResourceOperation}, branching on
 * whether the target carries an id — single (`fetchOne`) vs collection
 * (`fetchCollection`) — by delegating to the per-type
 * {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface} resolved from
 * the {@see DataProviderRegistry}, then wrapping the result in a core response
 * value object. A missing single resource renders a `404`
 * {@see ResourceNotFound}; any non-read operation (writes arrive in a later
 * phase) renders the same `404` placeholder. It stays PSR-7-free — the operation
 * already carries its context.
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

        return DataResponse::fromCollection(
            $provider->fetchCollection($type, $operation->queryParameters()),
            $serializer,
        );
    }
}
