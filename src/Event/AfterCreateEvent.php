<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;

/**
 * Dispatched after a create commits, before the aggregate {@see AfterSaveEvent}.
 * A subscriber may **replace** the `201` response via {@see setResponse()}; the
 * handler reads the (possibly replaced) {@see response()} back.
 */
final class AfterCreateEvent
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
