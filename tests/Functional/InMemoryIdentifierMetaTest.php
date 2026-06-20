<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifierMetaInMemoryTestKernel;

/**
 * {@see IdentifierMetaConformanceTestCase} against the in-memory provider: the
 * `identifierMeta()` resolvers read the related objects off the fully-materialised
 * object graph (core ADR 0084).
 */
final class InMemoryIdentifierMetaTest extends IdentifierMetaConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdentifierMetaInMemoryTestKernel::class;
    }
}
