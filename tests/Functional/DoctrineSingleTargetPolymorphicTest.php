<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\SingleTargetMorphDoctrineTestKernel;

/**
 * {@see SingleTargetPolymorphicConformanceTestCase} against the Doctrine provider.
 */
final class DoctrineSingleTargetPolymorphicTest extends SingleTargetPolymorphicConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return SingleTargetMorphDoctrineTestKernel::class;
    }
}
