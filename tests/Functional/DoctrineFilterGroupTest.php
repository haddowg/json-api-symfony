<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ConstrainedFilterDoctrineTestKernel;

/**
 * {@see FilterGroupConformanceTestCase} against the Doctrine provider: the
 * server-composed filter groups resolved as real DQL over an in-memory SQLite
 * database created per test and seeded with the canonical relationship graph
 * through {@see SeedsDoctrineRelationships} (Foundry).
 *
 * The asserted contract is that a `WhereAny` fan-out search, a `WhereAll` canned
 * toggle of fixed children, a nested `(A AND (B OR C))` and a standalone
 * `->fixed()` select exactly the same rows as the in-memory witness — the
 * `andX()`/`orX()` composite and the `->fixed()` deserialize-seam ride must match
 * the reference predicate recursion.
 */
final class DoctrineFilterGroupTest extends FilterGroupConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return ConstrainedFilterDoctrineTestKernel::class;
    }
}
