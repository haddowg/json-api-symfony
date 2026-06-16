<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see CursorConformanceTestCase} against the in-memory provider — the keyset
 * witness (the ground truth the Doctrine push-down matches; bundle ADR 0063).
 */
final class InMemoryCursorTest extends CursorConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
