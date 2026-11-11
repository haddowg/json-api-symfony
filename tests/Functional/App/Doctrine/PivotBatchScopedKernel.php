<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

final class PivotBatchScopedKernel extends PivotBatchKernel
{
    protected function windowFunctions(): bool
    {
        return true;
    }

    protected function scopesTracks(): bool
    {
        return true;
    }
}
