<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OutputTimezoneInMemoryTestKernel;

/**
 * {@see DeclaredLinksConformanceTestCase} against the in-memory provider.
 */
final class InMemoryDeclaredLinksTest extends DeclaredLinksConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OutputTimezoneInMemoryTestKernel::class;
    }
}
