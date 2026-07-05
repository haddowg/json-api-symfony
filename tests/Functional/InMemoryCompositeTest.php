<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Composite\CompositeInMemoryTestKernel;

/**
 * {@see CompositeConformanceTestCase} against the in-memory provider: the
 * composite values live as plain arrays in the shared store.
 */
final class InMemoryCompositeTest extends CompositeConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CompositeInMemoryTestKernel::class;
    }
}
