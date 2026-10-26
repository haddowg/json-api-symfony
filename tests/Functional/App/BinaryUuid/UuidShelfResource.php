<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: UuidShelfEntity::class)]
final class UuidShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function __construct(private readonly ?IdEncoderInterface $idEncoder = null) {}

    public function fields(): array
    {
        $id = Id::make()->uuid();
        if ($this->idEncoder !== null) {
            $id->encodeUsing($this->idEncoder);
        }

        return [
            $id,
            Str::make('name')->sortable(),
            HasMany::make('books', 'books')
                ->paginate(PagePaginator::make())
                ->countable()
                ->withSorts(SortByField::make('title', 'title')),
        ];
    }
}
