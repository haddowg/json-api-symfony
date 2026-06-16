<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;

/**
 * Dispatched after a collection is fetched (`GET /{type}`), before it renders.
 * {@see $items} is the materialized page/collection. A subscriber may **replace**
 * the response via {@see setResponse()}; the handler reads the (possibly replaced)
 * {@see response()} back.
 */
final class AfterFetchCollectionEvent
{
    private ?DataResponse $response = null;

    /**
     * @param list<object> $items the materialized collection items
     */
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly array $items,
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
