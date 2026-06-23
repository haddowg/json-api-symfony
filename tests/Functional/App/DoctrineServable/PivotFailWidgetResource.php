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
 * Guard A7 FAIL: a pivot `belongsToMany` (declaring `pivotFields()`) whose backing
 * association entity cannot be discovered. {@see GuardWidgetEntity} owns NO to-many
 * association reaching {@see GuardGadgetEntity}, and no `->through()` override names
 * the association entity, so the {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver}
 * auto-detect finds zero matches and throws. The
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineServableWarmer} runs that
 * discovery at `cache:warmup`, so the same `\LogicException` fires at build time
 * instead of the first relationship write.
 */
#[AsJsonApiResource(entity: GuardWidgetEntity::class)]
final class PivotFailWidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsToMany::make('gadgets', 'gadgets')
                ->fields(Integer::make('position')->min(1)),
        ];
    }
}
