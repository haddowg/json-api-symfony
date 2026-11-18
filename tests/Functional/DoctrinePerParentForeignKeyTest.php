<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\ForeignKeyPerParentTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\ForeignKeySeed;

/**
 * {@see ForeignKeyConformanceTestCase} on Doctrine with window functions off, where each
 * parent's page through a foreign key is its own bounded query.
 */
final class DoctrinePerParentForeignKeyTest extends ForeignKeyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ForeignKeyPerParentTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ForeignKeySeed::build() as $rows) {
            foreach ($rows as $row) {
                $entityManager->persist($row);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
