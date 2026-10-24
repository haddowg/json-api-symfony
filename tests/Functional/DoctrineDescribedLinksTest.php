<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DescribedLinks\DescribedLinksDoctrineTestKernel;

/**
 * {@see DescribedLinksConformanceTestCase} against the Doctrine provider, over an
 * in-memory SQLite database seeded per test.
 */
final class DoctrineDescribedLinksTest extends DescribedLinksConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DescribedLinksDoctrineTestKernel::class;
    }
}
