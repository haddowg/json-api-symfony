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

        $entityManager->persist(new BookingEntity(startsAt: new \DateTimeImmutable('2026-10-01T09:00:00Z'), day: new \DateTimeImmutable('2026-10-01'), dueOn: new \DateTimeImmutable('2026-12-24'), breakAt: new \DateTimeImmutable('12:30:00')));
        $entityManager->persist(new BookingEntity(startsAt: new \DateTimeImmutable('2026-10-02T15:00:00Z'), day: new \DateTimeImmutable('2026-10-02'), dueOn: new \DateTimeImmutable('2026-12-31'), breakAt: new \DateTimeImmutable('14:00:00')));
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
