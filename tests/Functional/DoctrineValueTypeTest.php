<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\SubmissionEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ValueTypesDoctrineTestKernel;

final class DoctrineValueTypeTest extends ValueTypeConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ValueTypesDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The store-provided `AUTO` id assigns it 1, matching the in-memory seed.
        $entityManager->persist(new SubmissionEntity(name: 'Seed'));
        $entityManager->flush();
        $entityManager->clear();
    }
}
