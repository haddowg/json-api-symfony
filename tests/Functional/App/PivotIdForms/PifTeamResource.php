<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PifTeam::class)]
final class PifTeamResource extends AbstractResource
{
    public static string $type = 'pif-teams';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new TeamIdEncoder()),
            Str::make('name'),
            BelongsToMany::make('players', 'pif-players')
                ->through(PifSeat::class)
                ->fields(Str::make('role')->nullable()->build())
                ->withFilters(
                    Where::make('roleIs', 'pivot.role'),
                    Where::make('roleNot', 'pivot.role', '<>'),
                    WhereIn::make('roleIn', 'pivot.role'),
                    WhereNotIn::make('roleNotIn', 'pivot.role'),
                    Where::make('seatRole', 'role')->through('pivot'),
                )
                ->withData(),
            BelongsToMany::make('squad', 'pif-squads')
                ->through(PifSeat::class)
                ->fields(Str::make('role')->nullable()->build())
                ->countable(),
            BelongsTo::make('coach', 'pif-coaches')->nullable(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereThrough::make('coachIs', 'coach'),
            WhereThrough::make('coachNot', 'coach')->operator('<>'),
            WhereThroughIn::make('coachIn', 'coach'),
        ];
    }
}
