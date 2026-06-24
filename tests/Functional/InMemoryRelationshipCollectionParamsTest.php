<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see RelationshipCollectionParamsConformanceTestCase} against the in-memory
 * provider: the queryable/paginated to-many relationship (linkage) endpoint over
 * the fully-materialised in-memory object graph.
 */
final class InMemoryRelationshipCollectionParamsTest extends RelationshipCollectionParamsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
