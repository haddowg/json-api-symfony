<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ConstrainedFilterDoctrineTestKernel;

/**
 * {@see FilterValueConstraintConformanceTestCase} against the Doctrine provider:
 * the constraint-bearing `filters()` resolved as real DQL over an in-memory
 * SQLite database created per test and seeded with the full canonical
 * relationship graph through {@see SeedsDoctrineRelationships} (Foundry).
 *
 * The asserted contract is that a mistyped `filter[id]=banana` is a clean `400`
 * with `source.parameter`, on the Doctrine provider as on the in-memory one: the
 * validation rejects it before any query runs, so the bad value never reaches the
 * data layer. (This kernel's sqlite is loosely typed, so an unvalidated mistyped
 * value would here silently non-match rather than `500`; the avoided PDO `500` is
 * specific to a strict driver such as Postgres — the point is the deliberate `400`
 * in place of either.)
 */
final class DoctrineFilterValueConstraintTest extends FilterValueConstraintConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return ConstrainedFilterDoctrineTestKernel::class;
    }
}
