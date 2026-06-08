<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see ReadQueryConformanceTestCase} against the in-memory provider — the
 * conformance witness half of the dual-provider contract.
 */
final class InMemoryReadQueryTest extends ReadQueryConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
