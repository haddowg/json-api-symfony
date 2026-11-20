<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CuratorEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\NestedFieldsetsDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PanelEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets\NestedFieldsetsFactory;

final class DoctrineNestedFieldsetsTest extends NestedFieldsetsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return NestedFieldsetsDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The store-provided `AUTO` ids assign 1 to each, matching the in-memory seed.
        $curator = new CuratorEntity(name: 'Ada', stats: NestedFieldsetsFactory::SEED_STATS, office: NestedFieldsetsFactory::SEED_OFFICE, exhibits: NestedFieldsetsFactory::SEED_EXHIBITS);
        $entityManager->persist($curator);
        $entityManager->persist(new PanelEntity(
            name: 'Seed',
            values: NestedFieldsetsFactory::SEED_VALUES,
            groups: NestedFieldsetsFactory::SEED_GROUPS,
            settings: NestedFieldsetsFactory::SEED_SETTINGS,
            curator: $curator,
            address: NestedFieldsetsFactory::SEED_ADDRESS,
            rows: NestedFieldsetsFactory::SEED_ROWS,
            archive: [],
            tags: NestedFieldsetsFactory::SEED_TAGS,
            blocks: NestedFieldsetsFactory::SEED_BLOCKS,
            hero: NestedFieldsetsFactory::SEED_HERO,
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
