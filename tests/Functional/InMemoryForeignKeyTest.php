<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\ForeignKeyInMemoryTestKernel;

/**
 * {@see ForeignKeyConformanceTestCase} with the related rows in memory.
 */
final class InMemoryForeignKeyTest extends ForeignKeyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ForeignKeyInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();
        ForeignKeyInMemoryTestKernel::reset();
    }
}
