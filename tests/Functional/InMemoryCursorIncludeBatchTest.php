<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see CursorIncludeBatchConformanceTestCase} against the in-memory provider — the
 * batched-include keyset witness (the ground truth the Doctrine path matches; each
 * parent's included cursor page is minted through the same per-parent
 * fetchRelatedCollection keyset path).
 */
final class InMemoryCursorIncludeBatchTest extends CursorIncludeBatchConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
