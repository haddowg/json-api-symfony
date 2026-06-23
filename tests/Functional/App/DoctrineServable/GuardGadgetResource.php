<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The far `gadgets` resource of the pivot-resolvability warm-up guard fixture
 * (guard A7): a plain entity-mapped resource a {@see GuardWidgetEntity} pivot
 * `belongsToMany` reaches.
 */
#[AsJsonApiResource(entity: GuardGadgetEntity::class)]
final class GuardGadgetResource extends AbstractResource
{
    public static string $type = 'gadgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
