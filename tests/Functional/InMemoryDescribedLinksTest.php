<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DescribedLinks\DescribedLinksInMemoryTestKernel;

/**
 * {@see DescribedLinksConformanceTestCase} against the in-memory provider.
 */
final class InMemoryDescribedLinksTest extends DescribedLinksConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DescribedLinksInMemoryTestKernel::class;
    }
}
