<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

/**
 * Flattens over `tags` directly (the LEAF to-many): `on()` flattens a scalar from a
 * to-one chain, so a to-many leaf segment is not flattenable and the boot-time
 * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator} throws (bundle ADR 0085).
 */
final class LeafToManyProductResource extends BaseEagerProductResource
{
    protected function flattenPath(): string
    {
        return 'tags';
    }
}
