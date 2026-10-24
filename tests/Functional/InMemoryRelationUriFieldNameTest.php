<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipMutationFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\UriFieldNameInMemoryTestKernel;

/**
 * {@see RelationUriFieldNameConformanceTestCase} against the in-memory provider.
 */
final class InMemoryRelationUriFieldNameTest extends RelationUriFieldNameConformanceTestCase
{
    protected function afterBoot(): void
    {
        RelationshipMutationFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return UriFieldNameInMemoryTestKernel::class;
    }
}
