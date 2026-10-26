<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints\EntityConstraintsInMemoryTestKernel;

final class InMemoryEntityConstraintsTest extends EntityConstraintsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EntityConstraintsInMemoryTestKernel::class;
    }
}
