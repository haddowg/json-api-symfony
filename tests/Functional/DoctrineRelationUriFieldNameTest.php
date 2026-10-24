<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\UriFieldNameDoctrineTestKernel;

/**
 * {@see RelationUriFieldNameConformanceTestCase} against the Doctrine provider.
 */
final class DoctrineRelationUriFieldNameTest extends RelationUriFieldNameConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return UriFieldNameDoctrineTestKernel::class;
    }
}
