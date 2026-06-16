<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see RelationCountConformanceTestCase} against the in-memory provider: the
 * countable relations are counted by reading the related set off each parent in
 * the fully-materialised object graph (bundle ADR 0052).
 */
final class InMemoryRelationCountTest extends RelationCountConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
