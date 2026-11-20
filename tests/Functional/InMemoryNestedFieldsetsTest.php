<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets\NestedFieldsetsFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets\NestedFieldsetsInMemoryTestKernel;

final class InMemoryNestedFieldsetsTest extends NestedFieldsetsConformanceTestCase
{
    protected function afterBoot(): void
    {
        NestedFieldsetsFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return NestedFieldsetsInMemoryTestKernel::class;
    }
}
