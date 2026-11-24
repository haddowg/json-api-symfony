<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixInMemoryTestKernel;

/**
 * {@see IdMatrixConformanceTestCase} in memory, where rows hold the value objects a
 * Doctrine hydration gives. An existence filter here walks the `notes` the seed puts on
 * each owner, since the in-memory filter handler reads relations off the model.
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
