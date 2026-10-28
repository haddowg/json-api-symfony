<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Renders its genre's name, so every rendered book reads its `genre` association.
 */
#[AsJsonApiResource(entity: HydrationBookEntity::class)]
final class HydrationBookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('genreName')
                ->extractUsing(static fn(mixed $book): ?string => $book instanceof HydrationBookEntity ? $book->genre?->name : null)
                ->readOnly(),
        ];
    }
}
