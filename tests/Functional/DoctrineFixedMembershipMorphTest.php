<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\FixedMtmDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmUser;

class DoctrineFixedMembershipMorphTest extends FixedMembershipMorphConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FixedMtmDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (MtmFixtures::graph() as $items) {
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

        return $this->entityManager()->find(MtmUser::class, $user)?->companyKeys() ?? [];
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
