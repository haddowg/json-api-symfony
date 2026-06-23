<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `readOnly` shorthand witness (E1): declaring `readOnly: true` on
 * {@see AsJsonApiResource} restricts the type to the two fetch operations
 * ({@see \haddowg\JsonApiBundle\Operation\Operation::FetchCollection} and
 * {@see \haddowg\JsonApiBundle\Operation\Operation::FetchOne}) — `GET /catalogues`
 * and `GET /catalogues/{id}` — without importing the `Operation` enum or spelling
 * the list out, and emits no `POST`/`PATCH`/`DELETE` routes. Because it exposes no
 * writes it needs only a provider to be servable (no persister, no hydrator).
 */
#[AsJsonApiResource(readOnly: true)]
final class ReadOnlyCatalogueResource extends AbstractResource
{
    public static string $type = 'catalogues';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->sortable(),
        ];
    }
}
