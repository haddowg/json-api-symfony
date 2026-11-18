<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A league whose teams page by cursor through their association entity, so a team sort
 * runs on the pivot query.
 */
#[AsJsonApiResource(entity: LeagueEntity::class, readOnly: true)]
final class LeagueResource extends AbstractResource
{
    public static string $type = 'leagues';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            BelongsToMany::make('teams', 'teams')
                ->fields(Integer::make('slot')->build())
                ->through(LeagueTeamEntity::class)
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
        ];
    }
}
