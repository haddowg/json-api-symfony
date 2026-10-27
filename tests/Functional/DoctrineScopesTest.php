<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Scopes\ScopesDoctrineTestKernel;

/**
 * {@see ScopesConformanceTestCase} against the Doctrine provider.
 */
final class DoctrineScopesTest extends ScopesConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return ScopesDoctrineTestKernel::class;
    }
}
