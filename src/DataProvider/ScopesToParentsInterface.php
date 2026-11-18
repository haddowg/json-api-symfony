<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * Tells the {@see RelatedReadRouter} whether a provider can scope its related reads to
 * parents of a given type. The Doctrine provider scopes a related query by the parent's
 * own mapping, so it can only for parents its entities map.
 *
 * When a provider answers `false` for a to-many with no foreign key, the bundle reads the
 * members the parent model holds and cuts each page in memory, as it does for an
 * `extractUsing()` relation, unless the parent type's provider batches the relation
 * itself. Optional: a provider that doesn't implement it is taken to scope to any parent.
 */
interface ScopesToParentsInterface
{
    /**
     * Whether this provider can read related rows scoped to a parent of `$parentType`.
     */
    public function scopesToParentsOf(string $parentType): bool;
}
