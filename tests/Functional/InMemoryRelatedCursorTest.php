<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see RelatedCursorConformanceTestCase} against the in-memory provider — the
 * related-collection keyset witness (the ground truth the Doctrine push-down
 * matches).
 */
final class InMemoryRelatedCursorTest extends RelatedCursorConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
