<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\FirstPage\FirstPageInMemoryTestKernel;

/**
 * {@see FirstPageConformanceTestCase} in memory.
 */
final class InMemoryFirstPageTest extends FirstPageConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FirstPageInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();
        FirstPageInMemoryTestKernel::reset();
    }
}
