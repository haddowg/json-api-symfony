<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Composite\CompositeInMemoryTestKernel;

/**
 * {@see StructuredListConformanceTestCase} against the in-memory provider: each
 * list lives as a plain array in the shared store.
 */
final class InMemoryStructuredListTest extends StructuredListConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CompositeInMemoryTestKernel::class;
    }
}
