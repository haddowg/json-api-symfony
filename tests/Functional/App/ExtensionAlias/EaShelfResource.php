<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: EaShelfEntity::class, readOnly: true)]
final class EaShelfResource extends AbstractResource
{
    public static string $type = 'eaShelves';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name')->sortable(),
            HasMany::make('books', 'eaBooks'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('bookTitle', 'title')->through('books'),
            WhereThrough::make('legacyBookTitle', 'books.title'),
        ];
    }
}
