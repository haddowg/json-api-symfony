<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;

/**
 * {@see RelationshipMutationConformanceTestCase} against the Doctrine persister:
 * the same replace/add/remove and guard assertions, executed as real
 * `getReference` + association mutation + `flush` over an in-memory SQLite
 * database seeded per test with the author/comment associations wired
 * ({@see SeedsDoctrineRelationships}). A re-fetch reads through the read provider,
 * proving the foreign keys were actually written.
 */
final class DoctrineRelationshipMutationTest extends RelationshipMutationConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    /**
     * Clear the identity map so a re-fetch genuinely reads the row back from
     * SQLite rather than returning the still-managed, just-mutated entity — the
     * persistence proof must distinguish a real foreign-key write from an
     * in-memory association change.
     */
    protected function detachPersistedState(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
    }
}
