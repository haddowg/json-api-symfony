<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WritableInMemoryTestKernel;

/**
 * The genericity witness over the in-memory provider/persister: the witness's
 * `tags` pair is seeded by {@see \haddowg\JsonApiBundle\Tests\Functional\App\WritableTagFactory},
 * so no `afterBoot()` seeding is needed.
 */
final class InMemoryGenericityWitnessTest extends GenericityWitnessConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return WritableInMemoryTestKernel::class;
    }
}
