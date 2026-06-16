<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see RelationshipQueriesConformanceTestCase} against the Doctrine provider: a
 * relationship is windowed to page 1 of its relatedQuery-ordered/filtered set by a
 * scoped DQL push-down on the related repo (the many-to-many `editors` subquery
 * scope for the countable case), over an in-memory SQLite database seeded per test
 * (bundle ADR 0053).
 */
final class DoctrineRelationshipQueriesTest extends RelationshipQueriesConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
