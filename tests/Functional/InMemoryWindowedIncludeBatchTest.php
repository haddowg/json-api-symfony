<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\WindowedIncludeWitnessKernel;

/**
 * {@see WindowedIncludeBatchConformanceTestCase} against the in-memory provider — the
 * GROUND TRUTH the Doctrine native batch and the per-parent fallback are both checked
 * against (bundle ADR 0065).
 */
final class InMemoryWindowedIncludeBatchTest extends WindowedIncludeBatchConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return WindowedIncludeWitnessKernel::class;
    }
}
