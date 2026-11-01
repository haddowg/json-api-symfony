<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\PaginatorEventInMemoryTestKernel;

/**
 * {@see CollectionEventPaginatorConformanceTestCase} against the in-memory provider.
 */
final class InMemoryCollectionEventPaginatorTest extends CollectionEventPaginatorConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return PaginatorEventInMemoryTestKernel::class;
    }
}
