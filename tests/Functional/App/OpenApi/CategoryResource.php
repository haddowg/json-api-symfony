<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The OpenAPI witness's second resource: a `categories` resource carrying **no**
 * explicit OpenAPI tags, so its operations group under the humanized-type **default**
 * tag (`Categories`) the {@see \haddowg\JsonApiBundle\OpenApi\Metadata\TagNameResolver}
 * derives — the witness for the default-tag path (design §4.7).
 *
 * It also exercises the **method-hook** description-override surface (bundle ADR 0092):
 * `getDescription()` overrides the resource-object schema description and
 * `describeOperation()` overrides one CRUD operation, while the others fall through to
 * the generated default — the method-hook twin of products' attribute-driven overrides.
 */
final class CategoryResource extends AbstractResource
{
    public static string $type = 'categories';

    /** @phpstan-ignore property.unusedType (a hook may legitimately return null to fall through to the default) */
    private ?string $resourceDescription = 'A grouping of related products.';

    /** @phpstan-ignore property.unusedType (a hook may legitimately return null to fall through to the default) */
    private ?string $deleteDescription = 'Permanently removes a category.';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
        ];
    }

    public function getDescription(): ?string
    {
        return $this->resourceDescription;
    }

    public function describeOperation(OperationType $op): ?string
    {
        return $op === OperationType::Delete ? $this->deleteDescription : null;
    }
}
