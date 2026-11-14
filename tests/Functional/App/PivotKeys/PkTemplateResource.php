<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use Doctrine\Common\Collections\Criteria;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\InstantiatesPivotInterface;

#[AsJsonApiResource(entity: PkTemplateEntity::class)]
final class PkTemplateResource extends AbstractResource implements InstantiatesPivotInterface
{
    public static string $type = 'campaign-templates';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name')->sortable(),
            BelongsToMany::make('placeholders', 'placeholder-definitions')
                ->through(PkTemplatePlaceholderEntity::class)
                ->fields(Str::make('defaultOverride')->nullable()->sortable()->build())
                ->withFilters(
                    Where::make('defaultOverride', 'pivot.defaultOverride'),
                    // Filters on the pivot row's own associations: a definition is
                    // unused on this template when its row here has no target.
                    WhereDoesntHave::make('unused', 'pivot.targets'),
                    WhereHas::make('used', 'pivot.targets'),
                    WhereThrough::make('targetName', 'pivot.targets.name'),
                    WhereThroughIn::make('target', 'pivot.targets')->uuid(),
                    // The row's own ends: named by the related and the parent type.
                    WhereThroughIn::make('rowDefinition', 'pivot.definition')->uuid(),
                    WhereThroughIn::make('rowTemplate', 'pivot.template'),
                    WhereHasMatching::criteria('targetedAtHeader', 'pivot.targets', Criteria::create()->where(Criteria::expr()->eq('name', 'header'))),
                )
                ->withData()
                ->paginate(PagePaginator::make())
                ->countable(),
            // Its pivot entity's constructor takes both ends, so instantiatePivot() builds it.
            BelongsToMany::make('pinned', 'placeholder-definitions')
                ->through(PkPinnedPlaceholderEntity::class)
                ->fields(Str::make('note')->nullable()->build())
                ->extractUsing(static fn(mixed $template): array => $template instanceof PkTemplateEntity
                    ? \array_values(\array_map(static fn(PkPinnedPlaceholderEntity $row): PkDefinitionEntity => $row->definition, $template->pinnedRows->toArray()))
                    : []),
        ];
    }

    public function instantiatePivot(HookContext $context, object $parent, object $related, array $pivot): ?object
    {
        if ($context->relation?->name() !== 'pinned' || !$parent instanceof PkTemplateEntity || !$related instanceof PkDefinitionEntity) {
            return null;
        }

        return new PkPinnedPlaceholderEntity($parent, $related, 'api:' . $context->serverName);
    }

    public function filters(): array
    {
        return [
            Where::make('name', 'name'),
            WhereThroughIn::make('definition', 'placeholderRows.definition'),
            WhereThrough::make('definitionIs', 'placeholderRows.definition'),
            WhereThroughIn::make('definitionKey', 'placeholderRows.definitionId'),
            WhereThroughIn::make('placeholder', 'placeholders'),
            WhereThrough::make('placeholderName', 'placeholders.name'),
            WhereHas::make('hasPlaceholders', 'placeholders'),
            WhereThroughIn::make('row', 'placeholderRows.id'),
            WhereThrough::make('rowIs', 'placeholderRows.id'),
        ];
    }
}
