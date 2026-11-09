<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes\ValueTypesInMemoryTestKernel;

final class InMemoryValueTypeTest extends ValueTypeConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ValueTypesInMemoryTestKernel::class;
    }
}
