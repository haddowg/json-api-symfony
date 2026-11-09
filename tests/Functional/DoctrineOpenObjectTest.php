<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\OpenObjectDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\SheetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenObject\SheetFactory;

final class DoctrineOpenObjectTest extends OpenObjectConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenObjectDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The store-provided `AUTO` id assigns it 1, matching the in-memory seed.
        $entityManager->persist(new SheetEntity(
            name: 'Seed',
            rows: SheetFactory::SEED_ROWS,
            labels: SheetFactory::SEED_LABELS,
            settings: SheetFactory::SEED_SETTINGS,
            metrics: SheetFactory::SEED_METRICS,
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
