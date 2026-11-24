<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixInMemoryTestKernel;

/**
 * {@see IdMatrixConformanceTestCase} in memory, where rows hold the value objects a
 * Doctrine hydration gives, and an existence filter reads a foreign-key relation through
 * the related type's provider, as Doctrine does.
 */
final class InMemoryIdMatrixTest extends IdMatrixConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdMatrixInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();
        IdMatrixInMemoryTestKernel::reset();
    }
}
