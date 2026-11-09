<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PrivateCollection;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PrivSquadEntity::class)]
final class PrivSquadResource extends AbstractResource
{
    public static string $type = 'squads';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            BelongsTo::make('captain', 'players')->withData(),
            BelongsToMany::make('players', 'players')->withData(),
            HasMany::make('recruits', 'players')->withData(),
        ];
    }
}
