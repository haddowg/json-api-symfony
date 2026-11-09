<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Guard A7 PASS: the same undiscoverable association as {@see PivotFailWidgetResource},
 * but every pivot field is computed, so the relation needs no association entity and
 * `cache:warmup` succeeds.
 */
#[AsJsonApiResource(entity: GuardWidgetEntity::class)]
final class ComputedPivotWidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('title'),
            BelongsToMany::make('gadgets', 'gadgets')
                ->fields(Integer::make('position')->computedUsing(static fn(): int => 1)->build()),
        ];
    }
}
