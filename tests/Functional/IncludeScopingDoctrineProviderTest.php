<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingProviderTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingAgency;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\ScopingFixtures;

/**
 * {@see IncludeScopingConformanceTestCase} where an application provider for the related types, wrapping Doctrine, hides the rows.
 */
final class IncludeScopingDoctrineProviderTest extends IncludeScopingConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IncludeScopingProviderTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ScopingFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function hiddenAgencyName(): string
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
        $agency = $entityManager->find(ScopingAgency::class, 2);
        \assert($agency instanceof ScopingAgency);

        return $agency->name;
    }
}
