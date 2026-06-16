<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\WindowedIncludeBatchOnKernel;

/**
 * {@see WindowedIncludeBatchConformanceTestCase} against the Doctrine NATIVE path with
 * `json_api.doctrine.window_functions: true` — the bounded ROW_NUMBER/COUNT OVER batch
 * (bundle ADR 0065). Asserts byte-identical documents to the in-memory witness, and the
 * bounded-fetch proof is in {@see DoctrineWindowedIncludeBatchBudgetTest}.
 */
final class DoctrineWindowedIncludeBatchOnTest extends WindowedIncludeBatchConformanceTestCase
{
    use SeedsLargeWindowedRelations;

    protected static function getKernelClass(): string
    {
        return WindowedIncludeBatchOnKernel::class;
    }
}
