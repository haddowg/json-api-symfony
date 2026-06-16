<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ConstrainedFilterInMemoryTestKernel;

/**
 * {@see WhereThroughConformanceTestCase} against the in-memory provider — the
 * conformance witness half of the dual-provider contract for the `WhereThrough`
 * traversal filter (core ADR 0063). Core's `ArrayFilterHandler` walks the dotted
 * path via the `Accessor`, fanning out across each to-many hop and matching on
 * EXISTS-ANY. The in-memory provider is seeded at construction from the canonical
 * fixtures (the comment → article back-references wired so the multi-hop case
 * chains identically to the Doctrine subquery), so no per-test seeding is needed.
 */
final class InMemoryWhereThroughTest extends WhereThroughConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ConstrainedFilterInMemoryTestKernel::class;
    }
}
