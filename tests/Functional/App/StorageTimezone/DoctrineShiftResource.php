<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;

/**
 * The shifts resource on Doctrine: adds the workers pivot with a writable date-time,
 * and stamps `stampedAt` itself on create, in UTC, to show the application's own
 * values are written as given.
 */
#[AsJsonApiResource(entity: ShiftEntity::class)]
final class DoctrineShiftResource extends BaseShiftResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public const string STAMP = '2026-10-03T16:00:00+00:00';

    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('workers', 'workers')
                ->through(ShiftWorkerEntity::class)
                ->fields(
                    DateTime::make('checkedInAt')->nullable()->build(),
                    DateTime::make('checkedOutAt')->nullable()->build(),
                )
                ->extractUsing(static function (mixed $shift): array {
                    if (!$shift instanceof ShiftEntity) {
                        return [];
                    }

                    $workers = [];
                    foreach ($shift->shiftWorkers ?? [] as $shiftWorker) {
                        if ($shiftWorker->worker !== null) {
                            $workers[] = $shiftWorker->worker;
                        }
                    }

                    return $workers;
                }),
        ];
    }

    public function beforeCreate(object $entity, HookContext $context): void
    {
        if ($entity instanceof ShiftEntity) {
            $entity->stampedAt = new \DateTimeImmutable(self::STAMP);
        }
    }
}
