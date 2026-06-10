<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see RelationshipReadConformanceTestCase} against the in-memory provider —
 * the conformance witness half of the dual-provider relationship-read contract.
 */
final class InMemoryRelationshipReadTest extends RelationshipReadConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
