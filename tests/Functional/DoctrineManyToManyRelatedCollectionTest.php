<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see ManyToManyRelatedCollectionConformanceTestCase} against the Doctrine
 * provider: the same many-to-many related-collection assertions executed as a
 * subquery-scoped DQL push-down on the related repo (the parent association is
 * owning-side / unidirectional, so membership scopes via an `IN` subquery) over
 * an in-memory SQLite database seeded per test ({@see SeedsDoctrineRelationships}).
 */
final class DoctrineManyToManyRelatedCollectionTest extends ManyToManyRelatedCollectionConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
