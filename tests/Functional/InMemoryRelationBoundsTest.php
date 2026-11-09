<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationBounds\RelationBoundsInMemoryTestKernel;

final class InMemoryRelationBoundsTest extends RelationBoundsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RelationBoundsInMemoryTestKernel::class;
    }
}
