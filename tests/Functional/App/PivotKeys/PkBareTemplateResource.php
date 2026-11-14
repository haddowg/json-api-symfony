<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Templates with no relation to a definition type, whose filter reaches definitions
 * through the pivot rows, so its declaration names no definition type.
 */
#[AsJsonApiResource(entity: PkTemplateEntity::class)]
final class PkBareTemplateResource extends AbstractResource
{
    public static string $type = 'bare-templates';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name')->sortable(),
        ];
    }

    public function filters(): array
    {
        return [WhereThroughIn::make('definition', 'placeholderRows.definition')];
    }
}
