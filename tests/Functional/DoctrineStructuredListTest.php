<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Composite\PipelineFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CompositeDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PipelineEntity;

/**
 * {@see StructuredListConformanceTestCase} against the Doctrine provider: each
 * structured list is a single `json` column on {@see PipelineEntity} in an
 * in-memory SQLite database.
 */
final class DoctrineStructuredListTest extends StructuredListConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CompositeDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The store-provided `AUTO` id assigns it 1, matching the in-memory seed.
        $entityManager->persist(new PipelineEntity(
            name: 'Seed',
            steps: PipelineFactory::SEED_STEPS,
            stops: PipelineFactory::SEED_STOPS,
        ));
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function detachPersistedState(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
    }
}
