<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fk-teams`, an entity with no mapping to its members, which it reads through their `team` relation.
 */
abstract class FkTeamResource extends AbstractResource
{
    public static string $type = 'fk-teams';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('members', 'fk-members', 'team')->paginate(PagePaginator::make()->withDefaultPerPage(2))->countable(),
        ];
    }
}
