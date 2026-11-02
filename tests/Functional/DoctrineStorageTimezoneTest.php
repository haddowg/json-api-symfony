<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\ShiftEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\ShiftFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\StorageTimezoneDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see StorageTimezoneConformanceTestCase} on Doctrine, plus checks on the local
 * time that actually lands in the naive column.
 */
final class DoctrineStorageTimezoneTest extends StorageTimezoneConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return StorageTimezoneDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (ShiftFixtures::data() as $row) {
            $entityManager->persist(new ShiftEntity(
                startsAt: ShiftFixtures::at($row['startsAt']),
                day: ShiftFixtures::at($row['day']),
            ));
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function theFixturesAreStoredAsLocalTime(): void
    {
        self::assertSame('2026-10-01 12:00:00', $this->storedStartsAt('1'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aWriteInUtcIsStoredAsLocalTime(): void
    {
        $created = $this->handle('/shifts', 'POST', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z'],
        ]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $id = $this->idOf($this->decode($created));
        self::assertSame('2026-10-03 12:00:00', $this->storedStartsAt($id));
    }

    #[Test]
    #[Group('crud:create')]
    public function theStoredLocalTimeFollowsTheDefaultTimezoneAtWriteTime(): void
    {
        // The zone is read on every write rather than when the container was built,
        // so an application that switches zones per request stores each in its own.
        \date_default_timezone_set('Europe/Paris');

        $created = $this->handle('/shifts', 'POST', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z'],
        ]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $id = $this->idOf($this->decode($created));
        self::assertSame('2026-10-03 18:00:00', $this->storedStartsAt($id));
    }

    private function storedStartsAt(string $id): mixed
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection->fetchOne('SELECT startsAt FROM tz_shift WHERE id = ?', [$id]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
