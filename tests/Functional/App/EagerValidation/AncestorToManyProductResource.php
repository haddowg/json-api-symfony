<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

/**
 * Flattens over `tags.region` — a multi-hop chain whose FIRST segment (`tags`) is a
 * to-many, even though it is an ANCESTOR, not the leaf. A to-many segment at ANY depth
 * is not flattenable, so the boot-time
 * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator} throws on the ancestor segment
 * (bundle ADR 0085).
 */
final class AncestorToManyProductResource extends BaseEagerProductResource
{
    protected function flattenPath(): string
    {
        return 'tags.region';
    }
}
