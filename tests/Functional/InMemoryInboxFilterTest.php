<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\InboxInMemoryTestKernel;

/**
 * {@see InboxFilterConformanceTestCase} against the in-memory provider, whose filters run
 * through core's reference `ArrayFilterHandler`.
 */
final class InMemoryInboxFilterTest extends InboxFilterConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return InboxInMemoryTestKernel::class;
    }
}
