<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched once per request, before the operation runs — the bundle's Symfony
 * event for core's server-level `serving` seam (core ADR 0050). The bundle
 * registers a `Server::withServing()` handler in
 * {@see \haddowg\JsonApiBundle\Server\ServerFactory} that fires this event inside
 * `Server::dispatch()`, so a core-direct consumer and a bundle consumer share the
 * same request-wide gate.
 *
 * It is a **before**-only gate: a subscriber that throws a
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} aborts the request
 * (the throw propagates out of the serving closure → out of `dispatch()` → the
 * route-scoped {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener}), so
 * the operation never runs. It carries no response — request-wide response
 * shaping belongs to the per-operation after events.
 */
final class ServingEvent
{
    public function __construct(
        public readonly JsonApiRequestInterface $request,
        public readonly string $serverName,
    ) {}
}
