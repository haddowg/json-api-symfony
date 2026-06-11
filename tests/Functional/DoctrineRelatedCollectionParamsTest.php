<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see RelatedCollectionParamsConformanceTestCase} against the Doctrine
 * provider: the same queryable/paginated related to-many assertions, executed as
 * a scoped DQL push-down on the related repo over an in-memory SQLite database
 * seeded per test ({@see SeedsDoctrineRelationships}).
 */
final class DoctrineRelatedCollectionParamsTest extends RelatedCollectionParamsConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
