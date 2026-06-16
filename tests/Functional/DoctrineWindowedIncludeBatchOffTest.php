<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\WindowedIncludeBatchOffKernel;

/**
 * {@see WindowedIncludeBatchConformanceTestCase} against the Doctrine per-parent BOUNDED
 * FALLBACK with `json_api.doctrine.window_functions: false` (bundle ADR 0065): a loop over
 * the proven single-parent fetch, each a real LIMIT push-down. Asserts the fallback
 * produces documents byte-identical to the native path AND the in-memory witness.
 */
final class DoctrineWindowedIncludeBatchOffTest extends WindowedIncludeBatchConformanceTestCase
{
    use SeedsLargeWindowedRelations;

    protected static function getKernelClass(): string
    {
        return WindowedIncludeBatchOffKernel::class;
    }
}
