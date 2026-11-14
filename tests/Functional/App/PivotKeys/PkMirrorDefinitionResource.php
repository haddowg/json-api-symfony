<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A second type over the definition entity with no encoder, like `placeholder-definitions`.
 */
#[AsJsonApiResource(entity: PkDefinitionEntity::class)]
final class PkMirrorDefinitionResource extends AbstractResource
{
    public static string $type = 'mirror-definitions';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('name'),
        ];
    }
}
