<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RequestAwarePredicatesFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\RequestAwarePredicatesInMemoryTestKernel;

/**
 * {@see RequestAwarePredicateConformanceTestCase} against the in-memory provider:
 * the request-aware predicates run over the writable in-memory `badges`/`medals`
 * graph. `afterBoot()` resets the factory so each test boots a fresh, unmutated
 * graph (a relationship mutation in one test must not bleed into the next).
 */
final class InMemoryRequestAwarePredicateTest extends RequestAwarePredicateConformanceTestCase
{
    protected function afterBoot(): void
    {
        RequestAwarePredicatesFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return RequestAwarePredicatesInMemoryTestKernel::class;
    }
}
