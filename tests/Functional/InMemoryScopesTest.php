<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Scopes\ScopesInMemoryTestKernel;

/**
 * {@see ScopesConformanceTestCase} against the in-memory provider.
 */
final class InMemoryScopesTest extends ScopesConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ScopesInMemoryTestKernel::class;
    }
}
