<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `segmentWidgets` on the default server, under its own URI segment, description and vendor extension.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class, server: 'default', readOnly: true, description: 'Alpha widgets.', singular: 'alpha widget', extensions: ['x-server' => 'default'])]
final class DefaultServerSegmentWidgetResource extends AbstractResource
{
    public static string $type = 'segmentWidgets';

    public static string $uriType = 'alpha-widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
