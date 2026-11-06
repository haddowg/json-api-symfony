<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Deprecation\DeprecationInMemoryTestKernel;

final class InMemoryDeprecationTest extends DeprecationConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DeprecationInMemoryTestKernel::class;
    }
}
