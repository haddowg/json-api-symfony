<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder\OrderInMemoryTestKernel;

final class InMemoryRelationOrderTest extends RelationOrderConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OrderInMemoryTestKernel::class;
    }
}
