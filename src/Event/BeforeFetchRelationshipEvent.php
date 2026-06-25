<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched before a **relationship-linkage** read renders
 * (`GET /{type}/{id}/relationships/{rel}`), but only for a relation that declares its
 * own read security ({@see RelationInterface::securityRead()}). The linkage twin of
 * {@see BeforeFetchRelatedEvent}: it carries the loaded {@see $parent} and the
 * {@see $relation} so a subscriber can authorize the relationship independently of its
 * parent.
 *
 * A relation that declares no read security keeps the parent's read gate
 * ({@see AfterFetchOneEvent}) instead; this event is not dispatched for it. A
 * subscriber that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * (or a Symfony {@see \Symfony\Component\Security\Core\Exception\AccessDeniedException},
 * mapped to a `403`) aborts the read.
 */
final class BeforeFetchRelationshipEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $parent,
        public readonly RelationInterface $relation,
        public readonly string $serverName,
    ) {}
}
