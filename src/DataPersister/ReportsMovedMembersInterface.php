<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A capability a {@see DataPersisterInterface} MAY implement when a to-many write
 * can change which parent a related resource belongs to: the inverse side of a
 * one-to-many, where each member holds a single reference to its parent. Before an
 * add or replace is applied, the handler asks which members would move, so each
 * move can be authorized against the member and the parent it leaves.
 *
 * The Doctrine persister implements it. A persister whose to-many members are not
 * owned by a single parent (the in-memory witness, a many-to-many) has nothing to
 * report and need not implement it.
 */
interface ReportsMovedMembersInterface
{
    /**
     * The members of `$linkage` that `$mode` would attach to `$parent` and that do not
     * already belong to it. Empty for {@see Mode::Remove}, and for a relation whose
     * members are not owned by a single parent.
     *
     * @return list<MovedMember>
     */
    public function movedMembers(string $type, object $parent, RelationInterface $relation, ToManyRelationship $linkage, Mode $mode): array;
}
