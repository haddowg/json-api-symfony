<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PrivateCollection;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PrivPlayerEntity::class)]
final class PrivPlayerResource extends AbstractResource
{
    public static string $type = 'players';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            BelongsTo::make('squad', 'squads')->withData(),
        ];
    }
}
