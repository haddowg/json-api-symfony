<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenObject\OpenObjectInMemoryTestKernel;

final class InMemoryOpenObjectTest extends OpenObjectConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenObjectInMemoryTestKernel::class;
    }
}
