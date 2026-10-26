<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EntityHooks\EntityHooksInMemoryTestKernel;

final class InMemoryEntityHooksTest extends EntityHooksConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EntityHooksInMemoryTestKernel::class;
    }
}
