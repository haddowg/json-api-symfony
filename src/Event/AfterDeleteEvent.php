<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\NoContentResponse;

/**
 * Dispatched after a delete commits. A subscriber may **replace** the `204`
 * response via {@see setResponse()} — e.g. return a `200` body for a soft-delete
 * that still renders the (now flagged) resource; the handler reads the (possibly
 * replaced) {@see response()} back.
 */
final class AfterDeleteEvent
{
    private DataResponse|NoContentResponse|null $response = null;

    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}

    public function setResponse(DataResponse|NoContentResponse|null $response): void
    {
        $this->response = $response;
    }

    public function response(): DataResponse|NoContentResponse|null
    {
        return $this->response;
    }
}
