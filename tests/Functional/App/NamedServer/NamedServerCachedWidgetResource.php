<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `cachedWidgets` the named server serves with a public cache.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class, server: 'v5', cacheHeaders: ['max_age' => 60, 'public' => true])]
final class NamedServerCachedWidgetResource extends AbstractResource
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
