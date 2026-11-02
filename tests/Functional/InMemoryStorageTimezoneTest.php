<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\StorageTimezoneInMemoryTestKernel;

final class InMemoryStorageTimezoneTest extends StorageTimezoneConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return StorageTimezoneInMemoryTestKernel::class;
    }
}
