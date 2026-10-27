<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks\ActionLinksInMemoryTestKernel;

/**
 * {@see ActionLinksConformanceTestCase} against the in-memory provider.
 */
final class InMemoryActionLinksTest extends ActionLinksConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionLinksInMemoryTestKernel::class;
    }
}
