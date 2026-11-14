<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Hook;

/**
 * Builds the association entity a write adds to a pivot `belongsToMany`, in place of
 * the persister's own instantiation. Implement it on the resource that declares the
 * relation, or on the type's standalone hydrator when it has no resource, when the
 * pivot entity's constructor takes arguments the persister can't supply.
 *
 * The Doctrine persister calls the constructor of a pivot entity that needs no
 * argument, and otherwise builds the row without it. Return null to keep that
 * default for a relation the hook doesn't build. The persister then sets the row's
 * parent and related associations and writes its pivot fields from `meta.pivot`, so
 * the hook needn't.
 *
 * `$context` carries the relation, the linkage and the mode of the write. `$pivot` is
 * the member's `meta.pivot` as sent. It runs for every new row, whether a relationship
 * endpoint, a whole-resource write or an Atomic Operations request adds it.
 */
interface InstantiatesPivotInterface
{
    /**
     * @param array<string, mixed> $pivot the member's `meta.pivot`, as sent
     */
    public function instantiatePivot(HookContext $context, object $parent, object $related, array $pivot): ?object;
}
