<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A division every team belongs to, whose teams render their first page with it best top
 * score first and lowest tier first by cursor, and most points first by page number,
 * through the teams' foreign key. A numbered window can't order by a custom sort, so that
 * one reads per parent.
 */
abstract class BaseDivisionResource extends AbstractResource
{
    public static string $type = 'divisions';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            HasMany::make('rankedTeams', 'teams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('topScore'), true))
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
            HasMany::make('tieredTeams', 'teams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('tier'), false))
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
            HasMany::make('pointedTeams', 'teams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('points'), true))
                ->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->withData(),
        ];
    }
}
