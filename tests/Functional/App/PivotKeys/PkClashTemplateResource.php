<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A pivot relation with a pivot-row filter into {@see PkClashDefinitionResource}, which
 * declares a relation stored as `pivot`, so the filter's path is ambiguous.
 */
#[AsJsonApiResource(entity: PkTemplateEntity::class)]
final class PkClashTemplateResource extends AbstractResource
{
    public static string $type = 'clash-templates';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            BelongsToMany::make('placeholders', 'clash-definitions')
                ->through(PkTemplatePlaceholderEntity::class)
                ->fields(Str::make('defaultOverride')->nullable()->build())
                ->withFilters(WhereDoesntHave::make('unused', 'pivot.targets')),
        ];
    }
}
