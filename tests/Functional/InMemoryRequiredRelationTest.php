<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation\RequiredRelationInMemoryTestKernel;

final class InMemoryRequiredRelationTest extends RequiredRelationConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RequiredRelationInMemoryTestKernel::class;
    }
}
