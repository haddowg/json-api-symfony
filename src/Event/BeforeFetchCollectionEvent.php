<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched before a collection is fetched (`GET /{type}`), ahead of the provider
 * query. A collection has no single subject, so — unlike {@see AfterFetchOneEvent} —
 * there is no entity; the event carries only the type, request, and server.
 *
 * A subscriber that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * (or a Symfony {@see \Symfony\Component\Security\Core\Exception\AccessDeniedException},
 * mapped to a `403`) aborts the read **before the query runs** — the natural place for
 * an all-or-nothing collection gate (`securityList`). Row-level read authorization
 * still belongs in the query scope (a Doctrine extension hiding rows → `404`); this
 * gate is for blanket-blocking the whole collection for a user or role.
 */
final class BeforeFetchCollectionEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly string $serverName,
    ) {}
}
