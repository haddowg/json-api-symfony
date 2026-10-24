<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched for each relationship a whole-resource create or update carries in
 * `data.relationships`, before the persister applies it (Atomic Operations `add` and
 * `update` included). The {@see $parent} is the resource being written: on update the
 * loaded resource, on create the fresh instance before its attributes are hydrated.
 * A subscriber that throws aborts the write before anything is committed.
 */
final class BeforeEmbeddedRelationshipWriteEvent
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
        public readonly bool $creating,
        public readonly string $serverName,
    ) {}
}
