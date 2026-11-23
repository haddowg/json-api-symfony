<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DateMessage\DateMessageInMemoryTestKernel;

final class InMemoryDateMessageTest extends DateMessageConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DateMessageInMemoryTestKernel::class;
    }
}
