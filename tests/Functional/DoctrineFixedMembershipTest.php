<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FixedMembershipDoctrineTestKernel;

/**
 * {@see FixedMembershipConformanceTestCase} against the Doctrine persister, reading the
 * members off the article's comment collection over in-memory SQLite.
 */
final class DoctrineFixedMembershipTest extends FixedMembershipConformanceTestCase
{
    use SeedsDoctrineRelationships;

    protected static function getKernelClass(): string
    {
        return FixedMembershipDoctrineTestKernel::class;
    }

    /**
     * Clear the identity map so a re-read sees what the database holds.
     */
    protected function detachPersistedState(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
    }
}
