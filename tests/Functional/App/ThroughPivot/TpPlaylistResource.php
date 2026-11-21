<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: TpPlaylistEntity::class, readOnly: true)]
final class TpPlaylistResource extends AbstractResource
{
    public static string $type = 'tpPlaylists';

    public function fields(): array
    {
        return [Id::make()->numeric(), Str::make('name')];
    }
}
