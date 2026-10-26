<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: UuidBookEntity::class)]
final class UuidBookResource extends AbstractResource
{
    public static string $type = 'books';

    public function __construct(private readonly ?IdEncoderInterface $idEncoder = null) {}

    public function fields(): array
    {
        $id = Id::make()->uuid();
        if ($this->idEncoder !== null) {
            $id->encodeUsing($this->idEncoder);
        }

        return [
            $id,
            Str::make('title')->sortable(),
            BelongsTo::make('shelf', 'shelves')->withData(),
            BelongsToMany::make('labels', 'labels')
                ->withData()
                ->paginate(PagePaginator::make())
                ->countable(),
            // Computed, so its members are narrowed through the provider's id-IN probe.
            HasMany::make('pickedLabels', 'labels')
                ->withData()
                ->extractUsing(static fn(mixed $book): array => $book instanceof UuidBookEntity ? $book->labels->toArray() : []),
        ];
    }
}
