<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ConstrainedFilterDoctrineTestKernel;

/**
 * {@see WhereThroughConformanceTestCase} against the Doctrine provider: each
 * traversal filter is executed as a correlated `EXISTS` DQL subquery rooted on the
 * related entity and correlated back to the outer owner, the intermediate segments
 * chained as inner joins and the leaf segment compared with the same operator /
 * `like` semantics as a plain `Where` (bundle ADR 0069). The in-memory SQLite
 * database is created per test and seeded with the full canonical relationship
 * graph through {@see SeedsDoctrineRelationships} (Foundry), so the SAME assertions
 * as the in-memory witness hold byte-for-byte.
 */
final class DoctrineWhereThroughTest extends WhereThroughConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return ConstrainedFilterDoctrineTestKernel::class;
    }
}
