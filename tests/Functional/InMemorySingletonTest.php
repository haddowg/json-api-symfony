<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Singleton\SingletonInMemoryTestKernel;

/**
 * {@see SingletonConformanceTestCase} in memory.
 */
final class InMemorySingletonTest extends SingletonConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return SingletonInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();
        SingletonInMemoryTestKernel::reset();
    }
}
