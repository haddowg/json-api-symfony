<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `cachedWidgets` the default server serves with a private cache, beside {@see NamedServerCachedWidgetResource}.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class, server: 'default', cacheHeaders: ['max_age' => 10, 'private' => true])]
final class DefaultServerCachedWidgetResource extends AbstractResource
{
    public static string $type = 'cachedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
