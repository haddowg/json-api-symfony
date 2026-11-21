<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions\ScInMemoryTestKernel;

final class InMemoryScopedConversionsTest extends ScopedConversionsTestCase
{
    protected static function getKernelClass(): string
    {
        return ScInMemoryTestKernel::class;
    }
}
