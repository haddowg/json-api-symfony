<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory `patternedWidgets` on the named server, with a lower-case letters id.
 */
#[AsJsonApiResource(server: 'v5', readOnly: true)]
final class InMemoryNamedServerPatternedWidgetResource extends AbstractResource
{
    public static string $type = 'patternedWidgets';

    public function fields(): array
    {
        return [
            Id::make()->matchAs('[a-z]+'),
            Str::make('name'),
        ];
    }
}
