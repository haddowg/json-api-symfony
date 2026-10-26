<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\EntityHooks\EntityHooksDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\EntityHooks\HookedNote;

final class DoctrineEntityHooksTest extends EntityHooksConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EntityHooksDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new HookedNote('Seeded'));
        $entityManager->flush();
        $entityManager->clear();
    }
}
