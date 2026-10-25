<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `codedWidgets` on the named server, whose ids read `b-<key>`.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class, server: 'v5', readOnly: true)]
final class NamedServerCodedWidgetResource extends AbstractResource
{
    public static string $type = 'codedWidgets';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixIdEncoder('b-')),
            Str::make('name'),
        ];
    }
}
