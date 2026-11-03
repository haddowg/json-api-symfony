<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\SortFamily\CampaignEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\SortFamily\CampaignFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\SortFamily\CampaignValueEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\SortFamily\SortFamilyDoctrineTestKernel;

final class DoctrineSortFamilyTest extends SortFamilyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return SortFamilyDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (CampaignFixtures::data() as $row) {
            $campaign = new CampaignEntity(name: $row['name']);
            $entityManager->persist($campaign);
            foreach ($row['values'] as $name => $value) {
                $entityManager->persist(new CampaignValueEntity(campaign: $campaign, name: $name, value: $value));
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
