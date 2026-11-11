<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

final class RunProgress
{
    public function __construct(
        public readonly string $id,
        public readonly int $runId,
        public readonly int $percent,
    ) {}
}
