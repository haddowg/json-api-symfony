<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples\OperationExamplesInMemoryTestKernel;

final class InMemoryOperationExamplesTest extends OperationExamplesConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OperationExamplesInMemoryTestKernel::class;
    }
}
