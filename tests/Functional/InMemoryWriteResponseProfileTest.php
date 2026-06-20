<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationInMemoryTestKernel;

/**
 * {@see WriteResponseProfileConformanceTestCase} against the in-memory provider:
 * the write kernel that seeds the full `articles`/`authors`/`comments` graph and
 * persists writes ({@see RelationshipMutationInMemoryTestKernel}), so a PATCH/POST
 * response renders the relationship windowing / counting seams off the seeded
 * membership.
 */
final class InMemoryWriteResponseProfileTest extends WriteResponseProfileConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RelationshipMutationInMemoryTestKernel::class;
    }
}
