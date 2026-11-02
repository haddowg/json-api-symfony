<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\ShiftFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\UtcStorageTimezoneDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * `storage_timezone: UTC` overrides PHP's default timezone, here New York, for the
 * values the Doctrine data layer binds and writes. Rows are seeded as raw UTC strings
 * so the assertions read the column itself.
 */
final class DoctrineConfiguredStorageTimezoneTest extends JsonApiFunctionalTestCase
{
    private string $previousTimezone;

    protected static function getKernelClass(): string
    {
        return UtcStorageTimezoneDoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();
        \date_default_timezone_set(ShiftFixtures::ZONE);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        \date_default_timezone_set($this->previousTimezone);
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The same instants as the shared fixtures, held as UTC wall-clock times.
        $connection = $this->connection();
        $connection->insert('tz_shift', ['id' => 1, 'startsAt' => '2026-10-01 16:00:00', 'day' => '2026-10-01']);
        $connection->insert('tz_shift', ['id' => 2, 'startsAt' => '2026-10-01 14:00:00', 'day' => '2026-09-30']);
        $connection->insert('tz_shift', ['id' => 3, 'startsAt' => '2026-10-02 13:00:00', 'day' => '2026-10-02']);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBoundIsComparedInTheConfiguredZone(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/shifts?filter[startsRange][min]=' . \rawurlencode('2026-10-01T11:00:00-04:00')));
        self::assertSame(['2'], $this->sortedIds('/shifts?filter[startsRange][max]=2026-10-01T15:00:00Z'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aWriteIsStoredInTheConfiguredZone(): void
    {
        $created = $this->handle('/shifts', 'POST', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T12:00:00-04:00'],
        ]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $data = $this->decode($created)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        self::assertSame('2026-10-03 16:00:00', $this->connection()->fetchOne('SELECT startsAt FROM tz_shift WHERE id = ?', [$id]));
    }

    #[Test]
    #[Group('crud:create')]
    public function aConfiguredZoneIgnoresARuntimeChangeOfTheDefault(): void
    {
        \date_default_timezone_set('Europe/Paris');

        $created = $this->handle('/shifts', 'POST', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z'],
        ]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $data = $this->decode($created)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        self::assertSame('2026-10-03 16:00:00', $this->connection()->fetchOne('SELECT startsAt FROM tz_shift WHERE id = ?', [$id]));
    }

    /**
     * @return list<string>
     */
    private function sortedIds(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
