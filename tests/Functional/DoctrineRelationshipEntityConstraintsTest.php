<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints\Fixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints\RelationshipEntityConstraintsDoctrineTestKernel;

final class DoctrineRelationshipEntityConstraintsTest extends RelationshipEntityConstraintsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RelationshipEntityConstraintsDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (Fixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
