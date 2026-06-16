<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Hook;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;

/**
 * No-op defaults for every {@see ResourceLifecycleHooksInterface} method, so a
 * resource opting into the lifecycle-hook seam (`use ResourceLifecycleHooksTrait;`
 * and `implements ResourceLifecycleHooksInterface`) overrides only the hooks it
 * actually needs — a before hook that does nothing never aborts, an after hook
 * that returns `null` never replaces the response.
 */
trait ResourceLifecycleHooksTrait
{
    public function beforeSave(object $entity, bool $creating, HookContext $context): void {}

    public function afterSave(object $entity, bool $creating, HookContext $context): ?DataResponse
    {
        return null;
    }

    public function beforeCreate(object $entity, HookContext $context): void {}

    public function afterCreate(object $entity, HookContext $context): ?DataResponse
    {
        return null;
    }

    public function beforeUpdate(object $entity, object $original, HookContext $context): void {}

    public function afterUpdate(object $entity, HookContext $context): ?DataResponse
    {
        return null;
    }

    public function beforeDelete(object $entity, HookContext $context): void {}

    public function afterDelete(object $entity, HookContext $context): DataResponse|NoContentResponse|null
    {
        return null;
    }

    public function beforeRelationshipMutate(object $parent, HookContext $context): void {}

    public function afterRelationshipMutate(object $parent, HookContext $context): ?IdentifierResponse
    {
        return null;
    }

    public function afterFetchOne(object $entity, HookContext $context): ?DataResponse
    {
        return null;
    }

    public function afterFetchCollection(array $items, HookContext $context): ?DataResponse
    {
        return null;
    }
}
