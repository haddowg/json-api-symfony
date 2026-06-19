<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ConstrainedFilterInMemoryTestKernel;

/**
 * {@see ConvenienceFilterConformanceTestCase} against the in-memory provider — the
 * conformance witness half of the dual-provider contract for the convenience filter
 * library (G8b). The in-memory provider is seeded at construction from the canonical
 * fixtures, so no per-test seeding is needed.
 */
final class InMemoryConvenienceFilterTest extends ConvenienceFilterConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ConstrainedFilterInMemoryTestKernel::class;
    }
}
