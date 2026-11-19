<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\ThroughAccessInMemoryKernel;

/**
 * {@see ThroughReadAccessConformance} on the in-memory kernel, where scoping providers
 * narrow the rows each hop reaches.
 */
class InMemoryThroughReadAccessTest extends JsonApiFunctionalTestCase
{
    use ThroughReadAccessConformance;

    protected static function getKernelClass(): string
    {
        return ThroughAccessInMemoryKernel::class;
    }
}
