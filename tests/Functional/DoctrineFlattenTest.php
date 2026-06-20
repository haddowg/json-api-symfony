<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FlattenDoctrineTestKernel;

/**
 * {@see FlattenConformanceTestCase} against the Doctrine provider (bundle ADR 0085):
 * the same flattened single- and multi-hop `on()` read/write, computed attribute, and
 * eager loads resolved over an in-memory SQLite database seeded by {@see SeedsFlatten}
 * (Foundry), served by the bundle's `-128` fallback Doctrine provider/persister. The
 * asserted contract is that the trio behaves identically to the in-memory witness —
 * provider-agnostic.
 */
final class DoctrineFlattenTest extends FlattenConformanceTestCase
{
    use SeedsFlatten;

    protected static function getKernelClass(): string
    {
        return FlattenDoctrineTestKernel::class;
    }
}
