<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;

/**
 * Dispatched after a single resource is fetched (`GET /{type}/{id}`), before it
 * renders. A subscriber may **replace** the response via {@see setResponse()}
 * (e.g. a custom-action shaping of the read); the handler reads the (possibly
 * replaced) {@see response()} back.
 */
final class AfterFetchOneEvent
{
    private ?DataResponse $response = null;

    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}

    public function setResponse(?DataResponse $response): void
    {
        $this->response = $response;
    }

    public function response(): ?DataResponse
    {
        return $this->response;
    }
}
