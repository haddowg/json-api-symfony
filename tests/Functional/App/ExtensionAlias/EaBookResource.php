<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: EaBookEntity::class, readOnly: true)]
final class EaBookResource extends AbstractResource
{
    public static string $type = 'eaBooks';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('title')->sortable(),
            BelongsTo::make('shelf', 'eaShelves')->nullable(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('shelfName', 'name')->through('shelf'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('shelfName', 'name')->through('shelf'),
        ];
    }
}
