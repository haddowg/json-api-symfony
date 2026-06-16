<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;

/**
 * The `hookableWidgets` resource — the resource-method mechanism witness: it
 * implements {@see ResourceLifecycleHooksInterface} (with no-op defaults from
 * {@see ResourceLifecycleHooksTrait}) and overrides each hook to record into the
 * shared {@see HookLog}, mirroring exactly what {@see RecordingHookSubscriber}
 * does on the event path — so the dual-mechanism conformance suite runs the same
 * assertions against this type as against the event-path `hookWidgets`.
 *
 * The built-in {@see \haddowg\JsonApiBundle\EventListener\ResourceHookSubscriber}
 * routes each lifecycle event to these methods, proving the methods are sugar over
 * the events. As a resource, it is its own serializer, so a replaced response is
 * built with `$this`.
 */
final class HookableWidgetResource extends BaseHookWidgetResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'hookableWidgets';

    public function beforeSave(object $entity, bool $creating, HookContext $context): void
    {
        HookLog::record('beforeSave');
        HookLog::maybeThrow('beforeSave');
    }

    public function afterSave(object $entity, bool $creating, HookContext $context): ?DataResponse
    {
        HookLog::record('afterSave');

        return HookLog::shouldReplace('afterSave') ? $this->replacement($entity, 'afterSave') : null;
    }

    public function beforeCreate(object $entity, HookContext $context): void
    {
        \assert($entity instanceof HookWidget);
        $entity->stamp = 'method-stamped';

        HookLog::record('beforeCreate');
        HookLog::maybeThrow('beforeCreate');
    }

    public function afterCreate(object $entity, HookContext $context): ?DataResponse
    {
        HookLog::record('afterCreate');

        return HookLog::shouldReplace('afterCreate') ? $this->replacement($entity, 'afterCreate') : null;
    }

    public function beforeUpdate(object $entity, object $original, HookContext $context): void
    {
        // The entity is the post-hydration incoming change; the original is the
        // pre-change snapshot routed through from the handler's clone.
        \assert($entity instanceof HookWidget);
        \assert($original instanceof HookWidget);
        HookLog::recordUpdateDiff($original->name, $entity->name);
        HookLog::maybeThrow('beforeUpdate');
    }

    public function afterUpdate(object $entity, HookContext $context): ?DataResponse
    {
        HookLog::record('afterUpdate');

        return HookLog::shouldReplace('afterUpdate') ? $this->replacement($entity, 'afterUpdate') : null;
    }

    public function beforeDelete(object $entity, HookContext $context): void
    {
        HookLog::record('beforeDelete');
        HookLog::maybeThrow('beforeDelete');
    }

    public function afterDelete(object $entity, HookContext $context): DataResponse|NoContentResponse|null
    {
        HookLog::record('afterDelete');

        return null;
    }

    public function beforeRelationshipMutate(object $parent, HookContext $context): void
    {
        HookLog::record('beforeRelationshipMutate');
        HookLog::maybeThrow('beforeRelationshipMutate');
    }

    public function afterRelationshipMutate(object $parent, HookContext $context): ?IdentifierResponse
    {
        HookLog::record('afterRelationshipMutate');

        return null;
    }

    public function afterFetchOne(object $entity, HookContext $context): ?DataResponse
    {
        HookLog::record('afterFetchOne');

        return HookLog::shouldReplace('afterFetchOne') ? $this->replacement($entity, 'afterFetchOne') : null;
    }

    public function afterFetchCollection(array $items, HookContext $context): ?DataResponse
    {
        HookLog::record('afterFetchCollection');

        return null;
    }

    private function replacement(object $entity, string $hook): DataResponse
    {
        return DataResponse::fromResource($entity, $this)->withMeta(['replacedBy' => $hook]);
    }
}
