<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PivotBatchOnKernel;

final class DoctrinePivotBatchOnTest extends DoctrinePivotBatchTestCase
{
    protected static function getKernelClass(): string
    {
        return PivotBatchOnKernel::class;
    }

    protected function hidesHiddenTrack(): bool
    {
        return false;
    }
}
