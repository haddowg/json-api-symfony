<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Hook;

/**
 * Builds the new model a create hydrates, in place of the persister's
 * `instantiate()`. Implement it on a type's resource, or on its standalone hydrator
 * when it has no resource, to run the entity's constructor or a factory: the Doctrine
 * persister otherwise builds the entity without calling its constructor.
 *
 * It runs for every create of the type, an Atomic Operations `add` included, before
 * the body's relationships and attributes are applied.
 */
interface InstantiatesEntityInterface
{
    public function instantiate(HookContext $context): object;
}
