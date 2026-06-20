<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

/**
 * Flattens over the valid multi-hop to-one chain `region.region` (product -> brand ->
 * region, both hops to-one and one hidden, one visible). The boot-time
 * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator} accepts it — no throw (bundle
 * ADR 0085).
 */
final class SafeProductResource extends BaseEagerProductResource
{
    protected function flattenPath(): string
    {
        return 'region.region';
    }
}
