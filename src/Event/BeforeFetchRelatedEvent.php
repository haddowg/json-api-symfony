<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched before a **related** read renders (`GET /{type}/{id}/{rel}`), but only
 * for a relation that declares its own read security ({@see RelationInterface::securityRead()}).
 * It carries the loaded {@see $parent} and the {@see $relation} so a subscriber can
 * authorize the relationship **independently** of its parent — the seam for a relation
 * that is more *or* less permissive than the resource it hangs off.
 *
 * A relation that declares no read security keeps the parent's read gate
 * ({@see AfterFetchOneEvent}) instead; this event is not dispatched for it. A
 * subscriber that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * (or a Symfony {@see \Symfony\Component\Security\Core\Exception\AccessDeniedException},
 * mapped to a `403`) aborts the read.
 */
final class BeforeFetchRelatedEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $parent,
        public readonly RelationInterface $relation,
        public readonly string $serverName,
    ) {}
}
