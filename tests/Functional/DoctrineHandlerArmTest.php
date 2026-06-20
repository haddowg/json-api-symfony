<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\RelationCountArmDoctrineTestKernel;

/**
 * {@see ExtensibleHandlerArmConformanceTestCase} against the Doctrine provider: the
 * custom count filter and sort resolved as real DQL by the registered Doctrine arms
 * (`SIZE(resource.comments)` as a push-down `>=` predicate and a `HIDDEN` ORDER BY
 * select) over an in-memory SQLite database seeded with the canonical relationship
 * graph through {@see SeedsDoctrineRelationships} (Foundry).
 *
 * The asserted contract is that the arm-driven custom filter and sort select and
 * order exactly the same rows on the Doctrine provider as on the in-memory witness.
 */
final class DoctrineHandlerArmTest extends ExtensibleHandlerArmConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return RelationCountArmDoctrineTestKernel::class;
    }
}
