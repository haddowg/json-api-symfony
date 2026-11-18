<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class BaseTeamResource extends AbstractResource
{
    public static string $type = 'teams';

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            Str::make('name')->sortable(),
        ];
    }

    public function sorts(): array
    {
        return [new TeamSort('topScore'), new TeamSort('tier'), new TeamSort('points')];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return CursorPaginator::make()->withDefaultSize(2);
    }
}
