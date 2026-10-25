<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `segmentWidgets` on the named server, declaring no URI segment, description or extension.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class, server: 'v5', readOnly: true)]
final class NamedServerSegmentWidgetResource extends AbstractResource
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
