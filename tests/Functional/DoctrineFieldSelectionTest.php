<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CuratorEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FieldSelectionDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PanelEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\FieldSelectionFactory;

final class DoctrineFieldSelectionTest extends FieldSelectionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FieldSelectionDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The store-provided `AUTO` ids assign 1 to each, matching the in-memory seed.
        $curator = new CuratorEntity(name: 'Ada', stats: FieldSelectionFactory::SEED_STATS);
        $entityManager->persist($curator);
        $entityManager->persist(new PanelEntity(
            name: 'Seed',
            values: FieldSelectionFactory::SEED_VALUES,
            groups: FieldSelectionFactory::SEED_GROUPS,
            settings: FieldSelectionFactory::SEED_SETTINGS,
            curator: $curator,
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
