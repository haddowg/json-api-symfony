<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\MultiType\MultiTypeFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\MultiTypeInMemoryTestKernel;

/**
 * {@see MultiTypeEntityConformanceTestCase} against the in-memory provider — the
 * conformance witness half of the dual-provider contract. `afterBoot()` resets the
 * factory so each test boots a fresh graph; the `members` and `public-members`
 * providers read the SAME Member objects (one record, two types).
 */
final class InMemoryMultiTypeEntityTest extends MultiTypeEntityConformanceTestCase
{
    protected function afterBoot(): void
    {
        MultiTypeFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return MultiTypeInMemoryTestKernel::class;
    }
}
