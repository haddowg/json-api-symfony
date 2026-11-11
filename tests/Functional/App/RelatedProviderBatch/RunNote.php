<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

final class RunNote
{
    public function __construct(
        public readonly int $id,
        public readonly int $runId,
        public readonly string $body,
    ) {}
}
