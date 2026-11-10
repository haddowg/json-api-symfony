<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A persister that writes the positions of a relation declared with `positionedBy()`
 * as it changes the relation's members. The handler calls it in place of
 * {@see DataPersisterInterface::mutateRelationship()} for such a relation, passing the
 * stored column the position lives in: on the link row of a pivot relation, on the
 * related row otherwise. A persister that doesn't implement it leaves positions to its
 * own {@see DataPersisterInterface::mutateRelationship()}.
 *
 * A replace writes each member's index in the linkage, an add appends after the highest
 * position, and a remove leaves gaps ({@see PositionTargets}). Since a position column is
 * normally unique per owner, a persister writes them in the order core's
 * {@see \haddowg\JsonApi\Resource\Field\Positions::plan()} gives: the removed members
 * first, then a temporary position for every row that moves, then the final positions.
 */
interface WritesPositionsInterface
{
    /**
     * {@see DataPersisterInterface::mutateRelationship()} for a positioned to-many, writing
     * the members' positions to `$column`.
     */
    public function mutatePositioned(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToManyRelationship $linkage,
        Mode $mode,
        string $column,
        bool $flush = true,
    ): object;

    /**
     * Writes positions onto rows a `fillUsing()` hook has already added or removed:
     * `$current` the positions before the write, `$target` every remaining member's
     * position after it, and `$rows` each remaining member's row, all keyed alike.
     *
     * @param array<string, int>    $current
     * @param array<string, int>    $target
     * @param array<string, object> $rows
     */
    public function writePositions(object $parent, array $current, array $target, array $rows, string $column, bool $flush = true): void;
}
