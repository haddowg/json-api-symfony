<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PivotBatchOffKernel;

final class DoctrinePivotBatchOffTest extends DoctrinePivotBatchTestCase
{
    protected static function getKernelClass(): string
    {
        return PivotBatchOffKernel::class;
    }

    protected function hidesHiddenTrack(): bool
    {
        return false;
    }
}
