<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationInMemoryTestKernel;

/**
 * {@see RelationshipMutationConformanceTestCase} against the in-memory persister:
 * the witness for the Doctrine relationship-mutation path, running zero-database
 * replace/add/remove through the shared in-memory object graph. `afterBoot()`
 * resets the factory so each test boots a fresh, unmutated graph.
 */
final class InMemoryRelationshipMutationTest extends RelationshipMutationConformanceTestCase
{
    protected function afterBoot(): void
    {
        RelationshipMutationFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return RelationshipMutationInMemoryTestKernel::class;
    }
}
