<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `plainWidgets` the default server serves with a private cache and the Gamma tag, beside {@see NamedServerPlainWidgetResource}.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class, server: 'default', cacheHeaders: ['max_age' => 15, 'private' => true], tags: ['Gamma'])]
final class DefaultServerPlainWidgetResource extends AbstractResource
{
    public static string $type = 'plainWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
