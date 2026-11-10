<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\CountThroughFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine\CountThroughDoctrineTestKernel;

/**
 * {@see RelationCountThroughConformanceTestCase} against the Doctrine provider, where
 * each relationship filter's EXISTS subquery correlates to the counted member.
 */
final class DoctrineRelationCountThroughTest extends RelationCountThroughConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CountThroughDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (CountThroughFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
