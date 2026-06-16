<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Include\IncludeSafeguardsTestKernel;

/**
 * {@see IncludeSafeguardsConformanceTestCase} against the in-memory provider —
 * the conformance witness half of the dual-provider include-safeguards contract
 * (bundle ADR 0037).
 */
final class InMemoryIncludeSafeguardsTest extends IncludeSafeguardsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IncludeSafeguardsTestKernel::class;
    }
}
