<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\SingleTargetMorphInMemoryTestKernel;

/**
 * {@see SingleTargetPolymorphicConformanceTestCase} against the in-memory provider.
 */
final class InMemorySingleTargetPolymorphicTest extends SingleTargetPolymorphicConformanceTestCase
{
    protected function afterBoot(): void
    {
        RelationshipMutationFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return SingleTargetMorphInMemoryTestKernel::class;
    }
}
