<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ConstrainedFilterInMemoryTestKernel;

/**
 * {@see FilterValueConstraintConformanceTestCase} against the in-memory provider —
 * the conformance witness half of the dual-provider contract for filter-value
 * constraints (bundle ADR 0048). The in-memory provider is seeded at construction
 * from the canonical fixtures, so no per-test seeding is needed.
 */
final class InMemoryFilterValueConstraintTest extends FilterValueConstraintConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ConstrainedFilterInMemoryTestKernel::class;
    }
}
