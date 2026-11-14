<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: PkDefinitionEntity::class)]
final class PkDefinitionResource extends AbstractResource
{
    public static string $type = 'placeholder-definitions';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('name')->sortable(),
            BelongsToMany::make('templates', 'campaign-templates')
                ->through(PkTemplatePlaceholderEntity::class)
                ->fields(Str::make('defaultOverride')->nullable()->sortable()->build())
                ->withFilters(Where::make('defaultOverride', 'pivot.defaultOverride'))
                ->withData()
                ->paginate(PagePaginator::make())
                ->countable(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('name', 'name'),
            WhereIdIn::make('id', 'id'),
            Where::make('idIs', 'id'),
            WhereThroughIn::make('template', 'usages.template'),
            WhereThroughIn::make('usedBy', 'templates'),
        ];
    }
}
