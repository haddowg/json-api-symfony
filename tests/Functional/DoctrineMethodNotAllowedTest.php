<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed\Advertiser;
use haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed\Agency;
use haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed\MethodNotAllowedDoctrineTestKernel;

final class DoctrineMethodNotAllowedTest extends MethodNotAllowedConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MethodNotAllowedDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $agency = new Agency();
        $agency->name = 'Seeded agency';
        $advertiser = new Advertiser();
        $advertiser->name = 'Seeded advertiser';
        $entityManager->persist($agency);
        $entityManager->persist($advertiser);
        $entityManager->flush();
        $entityManager->clear();
    }
}
