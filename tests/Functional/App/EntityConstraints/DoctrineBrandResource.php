<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Validation\Constraint\UniqueEntity;

/**
 * Adds a name unique among the brands that are not archived, reported at `label`.
 */
#[AsJsonApiResource(entity: Brand::class)]
final class DoctrineBrandResource extends BrandResource
{
    public function entityConstraints(): array
    {
        return [
            ...parent::entityConstraints(),
            new UniqueEntity(
                ['name'],
                message: 'An active brand already has this name.',
                repositoryMethod: 'findUnarchivedBy',
                errorPath: 'label',
                ignoreNull: true,
                entityClass: Brand::class,
            ),
        ];
    }
}
