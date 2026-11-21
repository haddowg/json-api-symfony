<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched once a relationship endpoint's parent is loaded and its linkage validated,
 * before the linkage is checked against the related type's provider and before anything
 * is applied (`PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{rel}`). The
 * {@see $parent} is the resource as stored. A subscriber that throws aborts the write
 * before the related ids are looked up, so a refused caller learns nothing about which of
 * them exist. The bundle's security subscriber gates the relationship write here; the
 * before hooks follow at {@see BeforeRelationshipMutateEvent}.
 */
final class BeforeApplyRelationshipEvent
{
    use ReadsWrittenMeta;

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
