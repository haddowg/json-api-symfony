<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Hook;

/**
 * Deletes a type's model in place of the persister's `delete()`, such as a soft
 * delete through a domain service. Implement it on the type's resource; every other
 * write still goes through the persister.
 *
 * It runs after the before-delete gates and before the after-delete hooks, inside an
 * Atomic Operations batch's transaction when there is one. It owns committing its
 * change: a Doctrine implementation flushes.
 */
interface DeletesEntityInterface
{
    public function delete(object $entity, HookContext $context): void;
}
