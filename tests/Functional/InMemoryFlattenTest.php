<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\FlattenInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\FlattenProviderFactory;

/**
 * {@see FlattenConformanceTestCase} against the in-memory provider (bundle ADR 0085):
 * the flattened single- and multi-hop `on()` read/write, the computed attribute, and
 * the eager loads run over the writable in-memory `books`/`authors`/`countries` graph.
 * `afterBoot()` resets the factory so each test boots a fresh, unmutated graph (a
 * flattened write in one test must not bleed into the next).
 */
final class InMemoryFlattenTest extends FlattenConformanceTestCase
{
    protected function afterBoot(): void
    {
        FlattenProviderFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return FlattenInMemoryTestKernel::class;
    }
}
