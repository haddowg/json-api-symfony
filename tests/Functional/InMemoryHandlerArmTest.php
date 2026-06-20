<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationCountArmInMemoryTestKernel;

/**
 * {@see ExtensibleHandlerArmConformanceTestCase} against the in-memory provider — the
 * conformance witness half: the custom count filter and sort run as registered
 * in-memory arms (a row predicate / a per-row sort key). The provider is seeded at
 * construction from the canonical fixtures, so no per-test seeding is needed.
 */
final class InMemoryHandlerArmTest extends ExtensibleHandlerArmConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RelationCountArmInMemoryTestKernel::class;
    }
}
