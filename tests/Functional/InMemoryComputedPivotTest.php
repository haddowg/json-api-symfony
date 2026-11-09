<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot\CpInMemoryTestKernel;

final class InMemoryComputedPivotTest extends ComputedPivotConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CpInMemoryTestKernel::class;
    }
}
