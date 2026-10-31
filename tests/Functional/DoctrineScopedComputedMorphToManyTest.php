<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmScopedDoctrineTestKernel;

final class DoctrineScopedComputedMorphToManyTest extends DoctrineComputedMorphToManyTest
{
    protected static function getKernelClass(): string
    {
        return CmScopedDoctrineTestKernel::class;
    }

    protected function scoped(): bool
    {
        return true;
    }
}
