<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: AssocConfigEntity::class)]
final class AssocConfigResource extends AbstractResource
{
    public static string $type = 'configs';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('label')->sortable(),
            BelongsToMany::make('runs', 'runs')
                ->withData()
                ->paginate(PagePaginator::make())
                ->countable(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('label', 'label'),
            WhereThrough::make('runName', 'runs.name'),
            WhereIdIn::make('id', 'campaign'),
        ];
    }
}
