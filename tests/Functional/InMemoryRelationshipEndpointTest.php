<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see RelationshipEndpointConformanceTestCase} against the in-memory provider:
 * the related/relationship-endpoint and compound-document assertions over the
 * fully-materialised in-memory object graph.
 */
final class InMemoryRelationshipEndpointTest extends RelationshipEndpointConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
