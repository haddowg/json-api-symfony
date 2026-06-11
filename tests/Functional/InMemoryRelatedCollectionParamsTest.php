<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see RelatedCollectionParamsConformanceTestCase} against the in-memory
 * provider: the queryable/paginated related to-many endpoint over the
 * fully-materialised in-memory object graph.
 */
final class InMemoryRelatedCollectionParamsTest extends RelatedCollectionParamsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
