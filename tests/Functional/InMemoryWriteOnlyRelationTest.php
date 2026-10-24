<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteOnlyRelationInMemoryTestKernel;

/**
 * {@see WriteOnlyRelationConformanceTestCase} against the in-memory provider.
 */
final class InMemoryWriteOnlyRelationTest extends WriteOnlyRelationConformanceTestCase
{
    protected function afterBoot(): void
    {
        RelationshipMutationFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return WriteOnlyRelationInMemoryTestKernel::class;
    }
}
