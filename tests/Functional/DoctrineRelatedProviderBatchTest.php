<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\Doctrine\RelatedProviderBatchDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch\RunFixtures;

/**
 * {@see RelatedProviderBatchConformanceTestCase} with the runs on the Doctrine provider.
 */
final class DoctrineRelatedProviderBatchTest extends RelatedProviderBatchConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return RelatedProviderBatchDoctrineTestKernel::class;
    }

    protected function seed(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        foreach (RunFixtures::runs() as $run) {
            $entityManager->persist($run);
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
