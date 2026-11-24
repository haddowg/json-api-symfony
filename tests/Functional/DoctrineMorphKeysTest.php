<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MorphKeysDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\MorphKeysFixtures;

/**
 * {@see MorphKeysConformanceTestCase} against the Doctrine provider, which admits the stored
 * ids of a scoped type through the related read's id path.
 */
final class DoctrineMorphKeysTest extends MorphKeysConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MorphKeysDoctrineKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (MorphKeysFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
