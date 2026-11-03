<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\SortFamily\SortFamilyInMemoryTestKernel;

final class InMemorySortFamilyTest extends SortFamilyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return SortFamilyInMemoryTestKernel::class;
    }
}
