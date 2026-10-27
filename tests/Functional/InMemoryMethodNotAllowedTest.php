<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed\MethodNotAllowedInMemoryTestKernel;

final class InMemoryMethodNotAllowedTest extends MethodNotAllowedConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MethodNotAllowedInMemoryTestKernel::class;
    }
}
