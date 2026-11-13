<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * A shelf's `books` and `featured` book are the relations `relatedQuery` addresses.
 * `drafts` is write-only and `mixed`, its books and the featured book's writer, is
 * polymorphic, so neither is addressable.
 */
abstract class BaseRqShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->sortable(),
            HasMany::make('books', 'books')->withData(),
            BelongsTo::make('featured', 'books'),
            HasMany::make('drafts', 'books')->storedAs('books')->writeOnly(),
            MorphToMany::make('mixed', ['books', 'people'])->readOnly()->extractUsing(
                static fn(mixed $shelf): array => $shelf instanceof RqShelf
                    ? \array_values(\array_filter([...$shelf->books->toArray(), $shelf->featured?->writer]))
                    : [],
            ),
        ];
    }

    public function filters(): array
    {
        return [Where::make('name')];
    }
}
