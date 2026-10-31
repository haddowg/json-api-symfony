<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph\CmUser;

class DoctrineComputedMorphToManyTest extends ComputedMorphToManyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return CmDoctrineTestKernel::class;
    }

    protected function scoped(): bool
    {
        return false;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (CmFixtures::graph() as $items) {
            foreach ($items as $item) {
                $entityManager->persist($item);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function storedCompanies(int $user): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(CmUser::class, $user)?->companyKeys() ?? [];
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
