<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\OptionNamedFieldsDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PresetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\OptionNamedFields\PresetFactory;

final class DoctrineOptionNamedFieldsTest extends OptionNamedFieldsConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return OptionNamedFieldsDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The store-provided `AUTO` id assigns it 1, matching the in-memory seed.
        $entityManager->persist(new PresetEntity(groups: 'seed', fields: 'seed', payload: 'seed', options: PresetFactory::SEED_OPTIONS));
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
