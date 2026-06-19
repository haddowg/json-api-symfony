<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see OpenApiConformanceTestCase} against the **in-memory** provider — the round-trip
 * conformance witness half. The generated document describes the in-memory provider's
 * actual wire responses for the `articles` / `authors` surface.
 */
final class InMemoryOpenApiConformanceTest extends OpenApiConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
