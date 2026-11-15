<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationInMemoryTestKernel;

/**
 * {@see MissingLinkageConformanceTestCase} against the in-memory persister.
 */
final class InMemoryMissingLinkageTest extends MissingLinkageConformanceTestCase
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
