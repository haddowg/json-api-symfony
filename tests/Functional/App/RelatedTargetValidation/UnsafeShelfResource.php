<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedTargetValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A shelf whose `crates` relation exposes `GET /shelves/{id}/crates` to a type the
 * server never registers. That endpoint would have to return a `crates` resource
 * object, and the server has neither a serializer to render one nor a field
 * inventory to describe one — so the
 * {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer} must reject it at
 * `cache:warmup`.
 */
final class UnsafeShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            HasMany::make('crates', 'crates'),
        ];
    }
}
