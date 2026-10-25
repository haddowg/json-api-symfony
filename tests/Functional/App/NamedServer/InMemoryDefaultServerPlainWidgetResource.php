<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory `plainWidgets` the default server serves with a private cache, while the named server's declares nothing.
 */
#[AsJsonApiResource(server: 'default', cacheHeaders: ['max_age' => 15, 'private' => true], tags: ['Gamma'])]
final class InMemoryDefaultServerPlainWidgetResource extends AbstractResource
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
