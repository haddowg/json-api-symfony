<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingProviderTestKernel;

/**
 * {@see IncludeScopingWriteConformanceTestCase} where an application provider for the
 * related types, wrapping Doctrine, hides the rows.
 */
final class IncludeScopingWriteDoctrineProviderTest extends IncludeScopingWriteConformanceTestCase
{
    use IncludeScopingDoctrineWrites;

    protected static function getKernelClass(): string
    {
        return IncludeScopingProviderTestKernel::class;
    }
}
