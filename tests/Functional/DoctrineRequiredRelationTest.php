<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation\Fixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation\RequiredRelationDoctrineTestKernel;

final class DoctrineRequiredRelationTest extends RequiredRelationConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RequiredRelationDoctrineTestKernel::class;
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
