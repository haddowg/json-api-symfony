<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PifPlayer::class)]
final class PifPlayerResource extends AbstractResource
{
    public static string $type = 'pif-players';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PlayerIdEncoder()),
            Str::make('name'),
            BelongsToMany::make('teams', 'pif-teams')
                ->through(PifSeat::class)
                ->fields(Str::make('role')->nullable()->build())
                ->withData(),
        ];
    }
}
