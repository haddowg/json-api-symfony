<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\MountedDoctrineTestKernel;

/**
 * {@see MountedServerLinksConformanceTestCase} against the Doctrine provider, over
 * an in-memory SQLite database seeded per test.
 */
final class DoctrineMountedServerLinksTest extends MountedServerLinksConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return MountedDoctrineTestKernel::class;
    }
}
