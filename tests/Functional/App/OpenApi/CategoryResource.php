<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The OpenAPI witness's second resource: a `categories` resource carrying **no**
 * explicit OpenAPI tags, so its operations group under the humanized-type **default**
 * tag (`Categories`) the {@see \haddowg\JsonApiBundle\OpenApi\Metadata\TagNameResolver}
 * derives — the witness for the default-tag path (design §4.7).
 */
final class CategoryResource extends AbstractResource
{
    public static string $type = 'categories';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
        ];
    }
}
