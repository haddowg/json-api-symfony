<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see CursorWithCountConformanceTestCase} against the in-memory provider.
 */
final class InMemoryCursorWithCountTest extends CursorWithCountConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
