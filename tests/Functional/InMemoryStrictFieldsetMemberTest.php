<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\StrictFieldsetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\StrictFieldsetInMemoryTestKernel;

/**
 * {@see StrictFieldsetMemberConformanceTestCase} against the in-memory provider — the
 * conformance witness half of the dual-provider contract. `afterBoot()` resets the
 * factory so each test boots a fresh graph.
 */
final class InMemoryStrictFieldsetMemberTest extends StrictFieldsetMemberConformanceTestCase
{
    protected function afterBoot(): void
    {
        StrictFieldsetFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return StrictFieldsetInMemoryTestKernel::class;
    }
}
