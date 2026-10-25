<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `codedWidgets` on the default server, whose ids read `a-<key>`.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class, server: 'default', readOnly: true)]
final class DefaultServerCodedWidgetResource extends AbstractResource
{
    public static string $type = 'codedWidgets';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixIdEncoder('a-')),
            Str::make('name'),
        ];
    }
}
