<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\FilterFamilyInMemoryTestKernel;

final class InMemoryFilterFamilyTest extends FilterFamilyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FilterFamilyInMemoryTestKernel::class;
    }
}
