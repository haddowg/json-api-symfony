<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;

/**
 * {@see RelationshipQueriesConformanceTestCase} against the in-memory provider: a
 * relationship is windowed to page 1 of its relatedQuery-ordered/filtered set by
 * reading the related objects off the parent and applying the criteria in PHP
 * (bundle ADR 0053).
 */
final class InMemoryRelationshipQueriesTest extends RelationshipQueriesConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }
}
