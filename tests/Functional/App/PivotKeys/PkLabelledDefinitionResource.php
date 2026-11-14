<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A second type over the definition entity whose ids pass {@see PrefixedUuidEncoder}.
 */
#[AsJsonApiResource(entity: PkDefinitionEntity::class)]
final class PkLabelledDefinitionResource extends AbstractResource
{
    public static string $type = 'labelled-definitions';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixedUuidEncoder()),
            Str::make('name'),
        ];
    }

    public function filters(): array
    {
        return [WhereIdIn::make('id', 'id')];
    }
}
