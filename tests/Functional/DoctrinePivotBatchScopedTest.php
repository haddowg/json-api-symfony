<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PivotBatchScopedKernel;

final class DoctrinePivotBatchScopedTest extends DoctrinePivotBatchTestCase
{
    protected static function getKernelClass(): string
    {
        return PivotBatchScopedKernel::class;
    }

    protected function hidesHiddenTrack(): bool
    {
        return true;
    }
}
