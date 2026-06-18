<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ResolvingServerInterface;

/**
 * The resolved context handed to an {@see ActionHandlerInterface::handle()}
 * (bundle ADR 0076, design §4). It carries everything the handler needs without
 * threading the server: the resolving {@see ResolvingServerInterface}, the
 * {@see ActionDescriptor}, the resolved {@see $entity} (resource scope; `null` for
 * collection scope), the hydrated {@see $input} (Document mode; `null` otherwise),
 * the {@see JsonApiRequestInterface} (always — the raw body + uploaded files for a
 * Raw-input action) and the {@see QueryParameters}.
 *
 * {@see serializer()} resolves the `outputType` serializer once, and the
 * convenience factories {@see data()}/{@see meta()}/{@see noContent()} pre-wire it,
 * so the handler returns a response in one call.
 */
final readonly class ActionContext
{
    public function __construct(
        private ResolvingServerInterface $server,
        private ActionDescriptor $descriptor,
        private ?object $entity,
        private ?object $input,
        private JsonApiRequestInterface $request,
        private QueryParameters $queryParameters,
    ) {}

    /**
     * The resolved entity for a resource-scope action (fetched via the type's
     * `DataProvider` before the handler runs), or `null` for a collection-scope
     * action.
     */
    public function entity(): ?object
    {
        return $this->entity;
    }

    /**
     * The hydrated input object for a {@see ActionInput::Document} action (the
     * request document validated then hydrated into a fresh `inputType` instance),
     * or `null` for a {@see ActionInput::None}/{@see ActionInput::Raw} action.
     */
    public function input(): ?object
    {
        return $this->input;
    }

    /**
     * The originating JSON:API request — always present; for a
     * {@see ActionInput::Raw} action this exposes the raw body and the uploaded
     * files (via the PSR-7 `getBody()`/`getUploadedFiles()` it extends).
     */
    public function request(): JsonApiRequestInterface
    {
        return $this->request;
    }

    public function queryParameters(): QueryParameters
    {
        return $this->queryParameters;
    }

    public function server(): ResolvingServerInterface
    {
        return $this->server;
    }

    /**
     * The serializer for the action's `outputType` (defaulting to the mount type) —
     * the serializer every {@see DataResponse}/{@see MetaResponse} the handler
     * returns should render through.
     */
    public function serializer(): SerializerInterface
    {
        return $this->server->serializerFor($this->descriptor->outputType);
    }

    /**
     * A {@see DataResponse} rendering `$data` through the `outputType` serializer —
     * a single resource document for an object, a collection document for an
     * iterable.
     *
     * @param object|iterable<mixed> $data
     */
    public function data(object|iterable $data): DataResponse
    {
        $serializer = $this->serializer();

        return \is_iterable($data)
            ? DataResponse::fromCollection($data, $serializer)
            : DataResponse::fromResource($data, $serializer);
    }

    /**
     * A meta-only {@see MetaResponse} seeded with `$meta`.
     *
     * @param array<string, mixed> $meta
     */
    public function meta(array $meta): MetaResponse
    {
        return MetaResponse::fromMeta($meta);
    }

    /**
     * A bodyless `204` {@see NoContentResponse}.
     */
    public function noContent(): NoContentResponse
    {
        return NoContentResponse::create();
    }
}
