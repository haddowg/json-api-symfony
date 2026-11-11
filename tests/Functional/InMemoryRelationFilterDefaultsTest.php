<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults\FdInMemoryTestKernel;

final class InMemoryRelationFilterDefaultsTest extends RelationFilterDefaultsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FdInMemoryTestKernel::class;
    }
}
