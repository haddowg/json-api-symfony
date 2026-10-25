<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory `cachedWidgets` the default server serves with a private cache, beside the named server's public one.
 */
#[AsJsonApiResource(server: 'default', cacheHeaders: ['max_age' => 10, 'private' => true])]
final class InMemoryDefaultServerCachedWidgetResource extends AbstractResource
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
