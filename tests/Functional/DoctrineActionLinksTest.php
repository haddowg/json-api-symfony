<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks\ActionLinksDoctrineTestKernel;

/**
 * {@see ActionLinksConformanceTestCase} against the Doctrine provider, over an in-memory
 * SQLite database seeded per test.
 */
final class DoctrineActionLinksTest extends ActionLinksConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return ActionLinksDoctrineTestKernel::class;
    }
}
