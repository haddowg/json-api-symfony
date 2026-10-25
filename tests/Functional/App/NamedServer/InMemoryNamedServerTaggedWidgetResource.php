<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory `taggedWidgets` the named server serves under the Beta tag.
 */
#[AsJsonApiResource(server: 'v5', tags: ['Beta'])]
final class InMemoryNamedServerTaggedWidgetResource extends AbstractResource
{
    public static string $type = 'taggedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
