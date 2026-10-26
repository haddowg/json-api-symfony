<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
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
    /**
     * The request attribute carrying the {@see ActionInput} the body was read in.
     *
     * @internal
     */
    public const string INPUT_ATTRIBUTE = '_jsonapi_action_input';

    /**
     * The request attribute carrying the media type the request body matched.
     *
     * @internal
     */
    public const string REQUEST_MEDIA_TYPE_ATTRIBUTE = '_jsonapi_action_request_media_type';

    /**
     * The request attribute carrying the response media type the `Accept` header picked.
     *
     * @internal
     */
    public const string RESPONSE_MEDIA_TYPE_ATTRIBUTE = '_jsonapi_action_response_media_type';

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
    /**
     * The media type of the request body, without parameters, as matched against the
     * action's accepted types: `text/csv` for a CSV upload to an action accepting
     * `text/*`. Null when the request has no body.
     */
    public function requestMediaType(): ?string
    {
        $mediaType = $this->request->getAttribute(self::REQUEST_MEDIA_TYPE_ATTRIBUTE);

        return \is_string($mediaType) ? $mediaType : null;
    }

    /**
     * The media type the response must be sent in, picked from the action's declared
     * responses by the request's `Accept` header: `application/vnd.api+json` for a
     * JSON:API answer, or a raw result's type such as `text/csv`.
     */
    public function responseMediaType(): ?string
    {
        $mediaType = $this->request->getAttribute(self::RESPONSE_MEDIA_TYPE_ATTRIBUTE);

        return \is_string($mediaType) ? $mediaType : null;
    }

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

    /**
     * A `202 Accepted` {@see AcceptedResponse} for an action that queues its work
     * rather than completing it inline: `$contentLocation` is the URL of the job
     * resource the client polls. Pass `$job` to render it as the `202` body's `data`
     * (through the action's `outputType` serializer); omit it for a meta-only status
     * document. Chain `->withRetryAfter()` / `->withMeta()` for the poll hint / status.
     */
    public function accepted(string $contentLocation, ?object $job = null): AcceptedResponse
    {
        $response = $job !== null
            ? AcceptedResponse::forResource($job, $this->serializer())
            : AcceptedResponse::fromMeta([]);

        return $response->withContentLocation($contentLocation);
    }

    /**
     * A `303 See Other` {@see SeeOtherResponse} redirecting the client to `$location` —
     * the completion half of the async lifecycle: a job-status action returns this,
     * once the queued work is done, pointing at the produced resource.
     */
    /**
     * A raw (non-JSON:API) body, sent as it is: a string, a stream resource or a PSR-7
     * stream. The media type defaults to the one the request negotiated, and must be
     * one of the action's declared raw results.
     *
     * @param string|resource|\Psr\Http\Message\StreamInterface $body
     * @param array<string, string>                              $headers such as `Content-Disposition`
     */
    public function raw(mixed $body, ?string $mediaType = null, int $status = 200, array $headers = []): RawResponse
    {
        return new RawResponse($body, $mediaType ?? $this->responseMediaType() ?? 'application/octet-stream', $status, $headers);
    }

    public function seeOther(string $location): SeeOtherResponse
    {
        return SeeOtherResponse::to($location);
    }
}
