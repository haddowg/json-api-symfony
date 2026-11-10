<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\SettingEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ValueTypesDoctrineTestKernel;

final class DoctrineUnionTest extends UnionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ValueTypesDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The store-provided `AUTO` id assigns it 1, matching the in-memory seed.
        $entityManager->persist(new SettingEntity(answer: 'yes', currency: 'GBP'));
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function detachPersistedState(): void
    {
        $this->entityManager()->clear();
    }

    protected function holdsTopLevelDatesAsStrings(): bool
    {
        return true;
    }

    protected function stored(string $id, string $property): mixed
    {
        $setting = $this->entityManager()->find(SettingEntity::class, (int) $id);
        self::assertInstanceOf(SettingEntity::class, $setting);

        return $setting->{$property};
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
