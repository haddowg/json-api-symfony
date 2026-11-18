<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\KeysetDqlInMemoryTestKernel;

final class InMemoryKeysetDqlTest extends KeysetDqlConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return KeysetDqlInMemoryTestKernel::class;
    }
}
