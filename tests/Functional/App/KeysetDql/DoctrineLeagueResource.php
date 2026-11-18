<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApi\Resource\Field\BelongsToManyBuilder;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: LeagueEntity::class, readOnly: true)]
final class DoctrineLeagueResource extends BaseLeagueResource
{
    protected function pivot(BelongsToManyBuilder $relation): BelongsToManyBuilder
    {
        return $relation->through(LeagueTeamEntity::class);
    }
}
