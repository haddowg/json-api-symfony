<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\BelongsToManyBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A league holding every team. `teams` pages by cursor through the teams' association
 * entity on Doctrine. `rankedTeams` and `rankedSlots` read the same teams best top score
 * first and render their first page with the league, through the join table and through
 * the association entity.
 */
abstract class BaseLeagueResource extends AbstractResource
{
    public static string $type = 'leagues';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            $this->pivot(BelongsToMany::make('teams', 'teams')->fields(Integer::make('slot')->build()))
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
            BelongsToMany::make('rankedTeams', 'teams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('topScore'), true))
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
            $this->pivot(BelongsToMany::make('rankedSlots', 'teams')->storedAs('teams')->fields(Integer::make('slot')->build()))
                ->defaultSort(new SortDirective(new TeamSort('topScore'), true))
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
        ];
    }

    protected function pivot(BelongsToManyBuilder $relation): BelongsToManyBuilder
    {
        return $relation;
    }
}
