<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DefaultFilterInMemoryTestKernel;

/**
 * {@see FilterDefaultConformanceTestCase} against the in-memory provider — the
 * conformance witness half of the dual-provider contract for filter defaults.
 */
final class InMemoryFilterDefaultTest extends FilterDefaultConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DefaultFilterInMemoryTestKernel::class;
    }
}
