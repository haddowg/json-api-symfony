<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookLog;

/**
 * The Doctrine `hookableWidgets` resource — the resource-method hook mechanism
 * witness on the **Doctrine** path: it implements
 * {@see ResourceLifecycleHooksInterface} and records into the shared
 * {@see HookLog} exactly as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookableWidgetResource}
 * does, so the dual-provider hooks suite runs the same assertions over a real
 * persist/flush. A before-create hook stamps the entity, proving the mutation
 * survives the flush; a before-hook throw aborts before any commit (no row is
 * written / removed).
 */
#[AsJsonApiResource(entity: HookWidgetEntity::class)]
final class DoctrineHookableWidgetResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'hookableWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('stamp'),
            BelongsTo::make('owner', 'hookOwners'),
        ];
    }

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
        \assert($entity instanceof HookWidgetEntity);
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
        // pre-change snapshot the handler cloned before the in-place dirty
        // hydration of the managed entity.
        \assert($entity instanceof HookWidgetEntity);
        \assert($original instanceof HookWidgetEntity);
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
