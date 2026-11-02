<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\DoctrineShiftResource;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\ShiftEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\ShiftFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\StorageTimezoneDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\WorkerEntity;
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
        $entityManager->persist(new WorkerEntity(name: 'Ana'));
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

    #[Test]
    #[Group('crud:create')]
    public function aValueTheApplicationSetsIsStoredAsGiven(): void
    {
        // Only date-times the request wrote are converted. The resource stamps
        // stampedAt in UTC itself, so its wall-clock time lands unchanged.
        $created = $this->handle('/shifts', 'POST', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z'],
        ]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $id = $this->idOf($this->decode($created));
        self::assertSame('2026-10-03 12:00:00', $this->storedStartsAt($id));
        self::assertSame('2026-10-03 16:00:00', $this->connection()->fetchOne('SELECT stampedAt FROM tz_shift WHERE id = ?', [$id]));
        self::assertSame('2026-10-03T16:00:00+00:00', DoctrineShiftResource::STAMP);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPivotDateTimeIsStoredAsLocalTime(): void
    {
        $response = $this->handle('/shifts/1/relationships/workers', 'POST', ['data' => [
            ['type' => 'workers', 'id' => '1', 'meta' => ['pivot' => ['checkedInAt' => '2026-10-01T16:00:00Z']]],
        ]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        self::assertSame('2026-10-01 12:00:00', $this->connection()->fetchOne('SELECT checkedInAt FROM tz_shift_worker WHERE shift_id = 1 AND worker_id = 1'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPivotDateTimeOnAMutableColumnIsStoredAsLocalTime(): void
    {
        $response = $this->handle('/shifts/1/relationships/workers', 'POST', ['data' => [
            ['type' => 'workers', 'id' => '1', 'meta' => ['pivot' => ['checkedOutAt' => '2026-10-01T18:30:00+02:00']]],
        ]]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());

        self::assertSame('2026-10-01 12:30:00', $this->connection()->fetchOne('SELECT checkedOutAt FROM tz_shift_worker WHERE shift_id = 1 AND worker_id = 1'));
    }

    #[Test]
    public function aDateBoundBindsWithTheColumnsMappedType(): void
    {
        $bounds = ['min' => '2026-10-01T15:00:00Z'];
        $handler = new DoctrineFilterHandler();

        // Doctrine would otherwise infer datetime_immutable from the value for every
        // column and format a zoned bound without its offset.
        $zoned = $this->boundParameter($handler, 'startsAtTz', $bounds);
        self::assertSame(Types::DATETIMETZ_IMMUTABLE, $zoned->getType());
        self::assertInstanceOf(\DateTimeImmutable::class, $zoned->getValue());
        self::assertSame('2026-10-01T15:00:00+00:00', $zoned->getValue()->format(\DateTimeInterface::ATOM));

        $naive = $this->boundParameter($handler, 'startsAt', $bounds);
        self::assertSame(Types::DATETIME_IMMUTABLE, $naive->getType());
        self::assertInstanceOf(\DateTimeImmutable::class, $naive->getValue());
        self::assertSame('2026-10-01T11:00:00-04:00', $naive->getValue()->format(\DateTimeInterface::ATOM));

        $date = $this->boundParameter($handler, 'day', ['min' => '2026-10-01T00:00:00Z']);
        self::assertSame(Types::DATE_IMMUTABLE, $date->getType());
        self::assertInstanceOf(\DateTimeImmutable::class, $date->getValue());
        self::assertSame('2026-10-01', $date->getValue()->format('Y-m-d'));
    }

    /**
     * @param array<string, string> $bounds
     */
    private function boundParameter(DoctrineFilterHandler $handler, string $column, array $bounds): Parameter
    {
        $query = $this->entityManager()->createQueryBuilder()->select('s')->from(ShiftEntity::class, 's');
        $handler->apply(DateRange::make('range', $column)->build(), $query, $bounds);

        $parameter = $query->getParameters()->first();
        self::assertInstanceOf(Parameter::class, $parameter);

        return $parameter;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function storedStartsAt(string $id): mixed
    {
        return $this->connection()->fetchOne('SELECT startsAt FROM tz_shift WHERE id = ?', [$id]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
