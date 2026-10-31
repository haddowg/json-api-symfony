<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

/**
 * Flattens over `nope` — a typo naming no declared relation. The boot-time
 * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator} throws so the mistake never
 * silently no-ops.
 */
final class UnknownSegmentProductResource extends BaseEagerProductResource
{
    protected function flattenPath(): string
    {
        return 'nope';
    }
}
