<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: TeamEntity::class, readOnly: true)]
final class UnkeyableSortTeamResource extends BaseTeamResource
{
    public function sorts(): array
    {
        return [...parent::sorts(), new UnkeyableSort()];
    }
}
