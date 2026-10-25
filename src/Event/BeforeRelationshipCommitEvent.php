<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched after a relationship change has been applied to {@see $parent} and before
 * it commits: on the relationship endpoints (`PATCH`/`POST`/`DELETE
 * /{type}/{id}/relationships/{rel}`, Atomic Operations relationship operations
 * included) and for each relationship set in a whole-resource update body. A subscriber
 * that throws aborts the write, and the change is rolled back when the persister
 * implements {@see \haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface}.
 */
final class BeforeRelationshipCommitEvent
{
    /**
     * @param ToOneRelationship|ToManyRelationship $linkage the linkage that was applied
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
