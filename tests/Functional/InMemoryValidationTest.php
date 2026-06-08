<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WritableInMemoryTestKernel;

/**
 * {@see ValidationConformanceTestCase} against the in-memory kernel: the witness
 * that the bridge's `422`/pointer behaviour is provider-agnostic.
 */
final class InMemoryValidationTest extends ValidationConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return WritableInMemoryTestKernel::class;
    }
}
