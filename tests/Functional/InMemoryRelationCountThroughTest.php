<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\CountThroughInMemoryTestKernel;

/**
 * {@see RelationCountThroughConformanceTestCase} against the in-memory provider.
 */
final class InMemoryRelationCountThroughTest extends RelationCountThroughConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CountThroughInMemoryTestKernel::class;
    }
}
