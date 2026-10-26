<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyRejection\ReadOnlyRejectionInMemoryTestKernel;

final class InMemoryReadOnlyRejectionTest extends ReadOnlyRejectionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ReadOnlyRejectionInMemoryTestKernel::class;
    }
}
