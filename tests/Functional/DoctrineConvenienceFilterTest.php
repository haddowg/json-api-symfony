<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ConstrainedFilterDoctrineTestKernel;

/**
 * {@see ConvenienceFilterConformanceTestCase} against the Doctrine provider: the
 * convenience filters resolved as real DQL over an in-memory SQLite database created
 * per test and seeded with the canonical relationship graph through
 * {@see SeedsDoctrineRelationships} (Foundry).
 *
 * The asserted contract is that the two NEW `starts`/`ends` wildcard-`LIKE`
 * operators and the structured `Range`'s two push-down `>=`/`<=` predicates select
 * exactly the same rows as the in-memory witness — and that a blank bound is
 * open-ended and a malformed bound a clean `400` — on the Doctrine provider as on
 * the in-memory one.
 */
final class DoctrineConvenienceFilterTest extends ConvenienceFilterConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return ConstrainedFilterDoctrineTestKernel::class;
    }
}
