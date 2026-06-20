<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\RequestAwarePredicatesDoctrineTestKernel;

/**
 * {@see RequestAwarePredicateConformanceTestCase} against the Doctrine provider: the
 * same request-aware predicates resolved over an in-memory SQLite database seeded by
 * {@see SeedsRequestAwarePredicates} (Foundry), served by the bundle's `-128`
 * fallback Doctrine provider/persister. The asserted contract is that the predicates
 * behave identically to the in-memory witness — they are provider-agnostic.
 */
final class DoctrineRequestAwarePredicateTest extends RequestAwarePredicateConformanceTestCase
{
    use SeedsRequestAwarePredicates;

    protected static function getKernelClass(): string
    {
        return RequestAwarePredicatesDoctrineTestKernel::class;
    }
}
