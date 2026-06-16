<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see ManyToManyRelatedCollectionConformanceTestCase} against the in-memory
 * provider: the many-to-many related to-many endpoint over the
 * fully-materialised in-memory object graph (the `editors` objects read off the
 * parent).
 */
final class InMemoryManyToManyRelatedCollectionTest extends ManyToManyRelatedCollectionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
