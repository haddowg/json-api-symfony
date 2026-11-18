<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Refuses a single read of a video named `classified`. A video named `restricted` is
 * readable, though the article gate would refuse it. Only a video has a `channel`, so a
 * video read as an article would render its channel unscoped.
 */
#[AsJsonApiResource(readOnly: true, securityRead: "object.name != 'classified'")]
class GatedVideoResource extends AbstractResource
{
    public static string $type = 'gatedVideos';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('channel', 'gatedChannels')->withData(),
        ];
    }
}
