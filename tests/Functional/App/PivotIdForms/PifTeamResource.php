<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
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
                ->withData(),
        ];
    }
}
