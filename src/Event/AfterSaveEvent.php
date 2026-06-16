<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;

/**
 * Dispatched after a create **or** an update commits — the aggregate write hook,
 * fired after the more specific {@see AfterCreateEvent}/{@see AfterUpdateEvent}.
 * {@see $creating} distinguishes create from update.
 *
 * A subscriber may **replace** the rendered response by calling
 * {@see setResponse()} (custom-action shaping); the handler reads the (possibly
 * replaced) {@see response()} back after dispatch.
 */
final class AfterSaveEvent
{
    private ?DataResponse $response = null;

    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly bool $creating,
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
