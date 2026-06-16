<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched before a relationship-endpoint mutation applies
 * (`PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{rel}`). The {@see $parent}
 * is the loaded, **mutable** owner; {@see $relation}, {@see $linkage} and
 * {@see $mode} describe the requested change. A subscriber that throws a
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} aborts before the
 * persister applies the change.
 */
final class BeforeRelationshipMutateEvent
{
    /**
     * @param ToOneRelationship|ToManyRelationship $linkage the parsed linkage
     */
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $parent,
        public readonly RelationInterface $relation,
        public readonly ToOneRelationship|ToManyRelationship $linkage,
        public readonly Mode $mode,
        public readonly string $serverName,
    ) {}
}
