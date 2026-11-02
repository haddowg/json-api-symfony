<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

/**
 * The shifts app with `storage_timezone: UTC`, so naive columns hold UTC whatever
 * PHP's default timezone is.
 */
final class UtcStorageTimezoneDoctrineTestKernel extends StorageTimezoneDoctrineTestKernel
{
    protected function storageTimezone(): string
    {
        return 'UTC';
    }

    protected function stem(): string
    {
        return 'storage-timezone-utc-doctrine';
    }
}
