<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Through\ThroughInMemoryTestKernel;

/**
 * {@see ThroughRelationsConformanceTestCase} against the in-memory providers, the
 * advertisers served by a custom provider whose scope hides one of them.
 */
final class InMemoryThroughRelationsTest extends ThroughRelationsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ThroughInMemoryTestKernel::class;
    }

    protected static function brokenKernel(): string
    {
        return ThroughInMemoryTestKernel::class;
    }
}
