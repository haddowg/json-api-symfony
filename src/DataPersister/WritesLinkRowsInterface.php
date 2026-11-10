<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Collection\LinkRows\LinkRowWritePlan;
use haddowg\JsonApi\Resource\Field\MorphToMany;

/**
 * A persister that writes the link rows of a `MorphToMany` declared with
 * `identifiedByRows()`. Core plans every write such a relation takes in a
 * {@see LinkRowWritePlan}; the handler builds the plan from the request and hands it
 * here in place of {@see DataPersisterInterface::mutateRelationship()}.
 *
 * Apply the passes in order, each complete before the next, so a unique owner and
 * position key holds: delete the rows in `deletes`, set the values of `temporary`, then
 * of `updates`, then create a row of the relation's `through()` class for each of
 * `inserts`, setting the owner through its association and converting each value to its
 * storage form.
 */
interface WritesLinkRowsInterface
{
    /**
     * Applies `$plan` to `$parent`'s link rows and returns the parent. The last pass is
     * committed only with `$flush`.
     */
    public function writeLinkRows(object $parent, MorphToMany $relation, LinkRowWritePlan $plan, bool $flush = true): object;
}
