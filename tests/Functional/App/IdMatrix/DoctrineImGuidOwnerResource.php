<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Adds `peers`, a pivot over {@see ImGuidSeat} carrying its stored `rank`.
 */
#[AsJsonApiResource(entity: ImGuidOwner::class)]
final class DoctrineImGuidOwnerResource extends ImGuidOwnerResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('peers', 'im-uuid-owners')
                ->through(ImGuidSeat::class)
                ->fields(Integer::make('rank')),
        ];
    }
}
