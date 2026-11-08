<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: AssocRunEntity::class)]
final class AssocRunResource extends AbstractResource
{
    public static string $type = 'runs';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name')->sortable(),
            BelongsTo::make('primary', 'configs')->withData(),
            BelongsToMany::make('configs', 'configs')
                ->withData()
                ->paginate(PagePaginator::make())
                ->countable(),
            HasMany::make('ownedConfigs', 'configs')
                ->withData()
                ->paginate(CursorPaginator::make())
                ->countable(),
            BelongsToMany::make('openConfigs', 'open-configs')
                ->storedAs('configs')
                ->withData()
                ->paginate(CursorPaginator::make()),
            HasMany::make('openOwnedConfigs', 'open-configs')
                ->storedAs('ownedConfigs')
                ->withData()
                ->paginate(PagePaginator::make()),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('name', 'name'),
            WhereThrough::make('configLabel', 'configs.label'),
        ];
    }
}
