<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmScopedInMemoryTestKernel;

final class InMemoryScopedComputedMorphToManyTest extends InMemoryComputedMorphToManyTest
{
    protected static function getKernelClass(): string
    {
        return CmScopedInMemoryTestKernel::class;
    }

    protected function scoped(): bool
    {
        return true;
    }
}
