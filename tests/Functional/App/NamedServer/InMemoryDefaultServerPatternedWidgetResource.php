<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory `patternedWidgets` on the default server, with a numeric id.
 */
#[AsJsonApiResource(server: 'default', readOnly: true)]
final class InMemoryDefaultServerPatternedWidgetResource extends AbstractResource
{
    public static string $type = 'patternedWidgets';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }
}
