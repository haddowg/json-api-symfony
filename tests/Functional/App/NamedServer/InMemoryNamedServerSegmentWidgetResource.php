<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory `segmentWidgets` on the named server, declaring no URI segment, description or extension.
 */
#[AsJsonApiResource(server: 'v5', readOnly: true)]
final class InMemoryNamedServerSegmentWidgetResource extends AbstractResource
{
    public static string $type = 'segmentWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
