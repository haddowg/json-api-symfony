<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see RelationshipCollectionParamsConformanceTestCase} against the Doctrine
 * provider: the same queryable/paginated relationship-linkage assertions, executed
 * as a scoped DQL push-down on the related repo over an in-memory SQLite database
 * seeded per test ({@see SeedsDoctrineRelationships}).
 */
final class DoctrineRelationshipCollectionParamsTest extends RelationshipCollectionParamsConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
