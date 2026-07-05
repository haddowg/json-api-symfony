<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see LinkageCursorConformanceTestCase} against the in-memory provider — the
 * linkage keyset witness (the ground truth the Doctrine push-down matches;
 * bundle ADR 0114).
 */
final class InMemoryLinkageCursorTest extends LinkageCursorConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
