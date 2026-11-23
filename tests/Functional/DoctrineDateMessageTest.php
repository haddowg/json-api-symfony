<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\DateMessage\BookingEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DateMessage\DateMessageDoctrineTestKernel;

final class DoctrineDateMessageTest extends DateMessageConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return DateMessageDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new BookingEntity(startsAt: new \DateTimeImmutable('2026-10-01T09:00:00Z'), day: new \DateTimeImmutable('2026-10-01')));
        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
