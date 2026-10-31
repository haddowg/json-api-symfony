<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints\RelationshipEntityConstraintsInMemoryTestKernel;

final class InMemoryRelationshipEntityConstraintsTest extends RelationshipEntityConstraintsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RelationshipEntityConstraintsInMemoryTestKernel::class;
    }
}
