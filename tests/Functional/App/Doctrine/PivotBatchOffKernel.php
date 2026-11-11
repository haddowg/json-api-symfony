<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

final class PivotBatchOffKernel extends PivotBatchKernel
{
    protected function windowFunctions(): bool
    {
        return false;
    }

    protected function scopesTracks(): bool
    {
        return false;
    }
}
