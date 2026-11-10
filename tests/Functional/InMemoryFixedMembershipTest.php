<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\AtomicInMemoryFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\FixedMembershipInMemoryTestKernel;

/**
 * {@see FixedMembershipConformanceTestCase} against the in-memory persister, reading the
 * members off the shared object graph. `afterBoot()` resets the graph per test.
 */
final class InMemoryFixedMembershipTest extends FixedMembershipConformanceTestCase
{
    protected function afterBoot(): void
    {
        AtomicInMemoryFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return FixedMembershipInMemoryTestKernel::class;
    }
}
