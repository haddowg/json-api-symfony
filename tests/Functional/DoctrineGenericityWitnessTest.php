<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\TagEntityFactory;

/**
 * The genericity witness over the Doctrine-sqlite provider: identical assertions
 * to {@see InMemoryGenericityWitnessTest}, served by the `-128` fallback Doctrine
 * provider/persister from the `tags` entity map alone. The schema is created and
 * the two witness tags seeded in `afterBoot()` (the in-memory database lives and
 * dies with the kernel's connection).
 */
final class DoctrineGenericityWitnessTest extends GenericityWitnessConformanceTestCase
{
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // No explicit id: the store-provided `AUTO` column assigns 1 then 2 in
        // insertion order against the freshly recreated schema.
        TagEntityFactory::createOne(['name' => 'PHP']);
        TagEntityFactory::createOne(['name' => 'Testing']);

        $entityManager->clear();
    }

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }
}
