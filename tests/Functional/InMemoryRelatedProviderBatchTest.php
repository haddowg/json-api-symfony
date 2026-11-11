<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\RelatedProviderBatchInMemoryTestKernel;

/**
 * {@see RelatedProviderBatchConformanceTestCase} with the runs on the in-memory provider.
 */
final class InMemoryRelatedProviderBatchTest extends RelatedProviderBatchConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RelatedProviderBatchInMemoryTestKernel::class;
    }
}
