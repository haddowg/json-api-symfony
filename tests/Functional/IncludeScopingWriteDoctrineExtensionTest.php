<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingDoctrineTestKernel;

/**
 * {@see IncludeScopingWriteConformanceTestCase} where a Doctrine query extension hides the rows.
 */
final class IncludeScopingWriteDoctrineExtensionTest extends IncludeScopingWriteConformanceTestCase
{
    use IncludeScopingDoctrineWrites;

    protected static function getKernelClass(): string
    {
        return IncludeScopingDoctrineTestKernel::class;
    }
}
