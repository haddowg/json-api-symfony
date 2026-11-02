<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\ShiftFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\StorageTimezoneDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\UtcShiftEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Columns mapped to an application's own UTC date-time types, with PHP's default
 * timezone set to New York. The custom type stores UTC digits itself, so a filter
 * bound, a write and a cursor key must each reach it as the right instant and bind
 * with the column's mapped type. Binding as plain `datetime` would compare or store
 * New York wall-clock digits against UTC ones.
 *
 * `startsAt` is the immutable `datetime_immutable_utc` column and `endsAt` the mutable
 * `datetime_utc` one. Both hold the same instant on every seeded row. Rows 4 to 6 sit
 * either side of the New York fall-back on 1 November, so rows 4 and 6 share the
 * local time 01:10 although they are an hour apart.
 */
final class DoctrineCustomUtcTypeTest extends JsonApiFunctionalTestCase
{
    /**
     * Row id to UTC instant. The ids are in insertion order; the instants are not.
     */
    private const array ROWS = [
        1 => '2026-10-01 16:00:00',
        2 => '2026-10-01 14:00:00',
        3 => '2026-10-02 13:00:00',
        4 => '2026-11-01 05:10:00',
        5 => '2026-11-01 05:50:00',
        6 => '2026-11-01 06:10:00',
        7 => '2026-11-01 03:30:00',
    ];

    /**
     * The ids ordered by instant. Ordered by New York wall-clock time, 6 would come
     * before 5.
     */
    private const array CHRONOLOGICAL = ['2', '1', '3', '7', '4', '5', '6'];

    /**
     * Row id to the New York time in the plain `datetime` column `loggedAt` and the
     * day in the plain `date` column `onDay`. The days tie, so the id breaks them.
     */
    private const array PLAIN = [
        1 => ['2026-10-05 09:00:00', '2026-10-02'],
        2 => ['2026-10-03 09:00:00', '2026-10-01'],
        3 => ['2026-10-04 09:00:00', '2026-10-02'],
        4 => ['2026-10-01 09:00:00', '2026-10-01'],
        5 => ['2026-10-02 09:00:00', '2026-10-03'],
        6 => ['2026-10-06 09:00:00', '2026-10-03'],
        7 => ['2026-10-07 09:00:00', '2026-10-01'],
    ];

    private string $previousTimezone;

    protected static function getKernelClass(): string
    {
        return StorageTimezoneDoctrineTestKernel::class;
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
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Seeded in New York time, so the column types do the conversion to UTC.
        $newYork = new \DateTimeZone(ShiftFixtures::ZONE);
        foreach (self::ROWS as $id => $instant) {
            $utc = new \DateTimeImmutable($instant, new \DateTimeZone('UTC'));
            $entityManager->persist(new UtcShiftEntity(
                startsAt: $utc->setTimezone($newYork),
                endsAt: \DateTime::createFromImmutable($utc)->setTimezone($newYork),
                loggedAt: new \DateTime(self::PLAIN[$id][0]),
                onDay: new \DateTime(self::PLAIN[$id][1]),
            ));
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function theColumnsHoldUtcDigitsOnTheImmutableType(): void
    {
        $this->assertTheColumnsHoldUtcDigits('startsAt');
    }

    #[Test]
    public function theColumnsHoldUtcDigitsOnTheMutableType(): void
    {
        $this->assertTheColumnsHoldUtcDigits('endsAt');
    }

    private function assertTheColumnsHoldUtcDigits(string $column): void
    {
        foreach (self::ROWS as $id => $instant) {
            self::assertSame($instant, $this->stored($column, (string) $id), 'row ' . $id);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeInUtcSelectsByInstantOnTheImmutableType(): void
    {
        $this->assertADateRangeInUtcSelectsByInstant('startsRange');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeInUtcSelectsByInstantOnTheMutableType(): void
    {
        $this->assertADateRangeInUtcSelectsByInstant('endsRange');
    }

    private function assertADateRangeInUtcSelectsByInstant(string $filter): void
    {
        // In New York digits the max bound is 09:00 on 2 October, which would drop
        // row 3 and let row 2 in.
        self::assertSame(['1', '3'], $this->filtered($filter, '2026-10-01T15:00:00Z', '2026-10-02T13:00:00Z'));
        self::assertSame(['2'], $this->filtered($filter, null, '2026-10-01T15:00:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeWithOffsetsSelectsByInstantOnTheImmutableType(): void
    {
        $this->assertADateRangeWithOffsetsSelectsByInstant('startsRange');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeWithOffsetsSelectsByInstantOnTheMutableType(): void
    {
        $this->assertADateRangeWithOffsetsSelectsByInstant('endsRange');
    }

    private function assertADateRangeWithOffsetsSelectsByInstant(string $filter): void
    {
        self::assertSame(['1', '3'], $this->filtered($filter, '2026-10-01T17:00:00+02:00', '2026-10-02T09:00:00-04:00'));
        self::assertSame(['6'], $this->filtered($filter, '2026-11-01T01:00:00-05:00', '2026-11-01T01:30:00-05:00'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBoundOnTheInstantIncludesTheRowAndASecondLaterExcludesItOnTheImmutableType(): void
    {
        $this->assertABoundOnTheInstantIncludesTheRowAndASecondLaterExcludesIt('startsRange');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBoundOnTheInstantIncludesTheRowAndASecondLaterExcludesItOnTheMutableType(): void
    {
        $this->assertABoundOnTheInstantIncludesTheRowAndASecondLaterExcludesIt('endsRange');
    }

    private function assertABoundOnTheInstantIncludesTheRowAndASecondLaterExcludesIt(string $filter): void
    {
        self::assertSame(['1'], $this->filtered($filter, '2026-10-01T16:00:00Z', '2026-10-01T16:00:00Z'));
        self::assertSame(['1'], $this->filtered($filter, '2026-10-01T18:00:00+02:00', '2026-10-01T12:00:00-04:00'));
        self::assertSame([], $this->filtered($filter, '2026-10-01T18:00:01+02:00', '2026-10-01T16:30:00Z'));
        self::assertSame([], $this->filtered($filter, '2026-10-01T15:30:00Z', '2026-10-01T11:59:59-04:00'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aCreateStoresUtcDigitsAndReadsBackTheSameInstant(): void
    {
        $created = $this->handle('/utcShifts', 'POST', ['data' => [
            'type' => 'utcShifts',
            'attributes' => [
                'startsAt' => '2026-10-03T16:00:00Z',
                'endsAt' => '2026-10-03T18:30:00+02:00',
            ],
        ]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $id = $this->idOf($this->decode($created));

        self::assertSame('2026-10-03 16:00:00', $this->stored('startsAt', $id));
        self::assertSame('2026-10-03 16:30:00', $this->stored('endsAt', $id));

        $attributes = $this->fetchedAttributes($id);
        self::assertSame('2026-10-03T16:00:00+00:00', $attributes['startsAt'] ?? null);
        self::assertSame('2026-10-03T16:30:00+00:00', $attributes['endsAt'] ?? null);
    }

    #[Test]
    #[Group('crud:update')]
    public function anUpdateStoresUtcDigitsAndReadsBackTheSameInstant(): void
    {
        $updated = $this->handle('/utcShifts/2', 'PATCH', ['data' => [
            'type' => 'utcShifts',
            'id' => '2',
            'attributes' => [
                'startsAt' => '2026-10-01T18:30:00+02:00',
                'endsAt' => '2026-10-01T20:00:00Z',
            ],
        ]]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());

        self::assertSame('2026-10-01 16:30:00', $this->stored('startsAt', '2'));
        self::assertSame('2026-10-01 20:00:00', $this->stored('endsAt', '2'));

        $attributes = $this->fetchedAttributes('2');
        self::assertSame('2026-10-01T16:30:00+00:00', $attributes['startsAt'] ?? null);
        self::assertSame('2026-10-01T20:00:00+00:00', $attributes['endsAt'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagesWalkEveryRowOnceInInstantOrderOnTheImmutableType(): void
    {
        $this->assertCursorPagesWalkEveryRowOnceInInstantOrder('startsAt');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagesWalkEveryRowOnceInInstantOrderOnTheMutableType(): void
    {
        $this->assertCursorPagesWalkEveryRowOnceInInstantOrder('endsAt');
    }

    private function assertCursorPagesWalkEveryRowOnceInInstantOrder(string $column): void
    {
        self::assertSame(self::CHRONOLOGICAL, $this->walk('/utcShifts?sort=' . $column . '&page[size]=2'));
        self::assertSame(\array_reverse(self::CHRONOLOGICAL), $this->walk('/utcShifts?sort=-' . $column . '&page[size]=2'));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCursorKeyWithAnotherOffsetComparesTheInstantOnTheImmutableType(): void
    {
        $this->assertACursorKeyWithAnotherOffsetComparesTheInstant('startsAt');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCursorKeyWithAnotherOffsetComparesTheInstantOnTheMutableType(): void
    {
        $this->assertACursorKeyWithAnotherOffsetComparesTheInstant('endsAt');
    }

    private function assertACursorKeyWithAnotherOffsetComparesTheInstant(string $column): void
    {
        // A key read off an entity the application set in another zone carries that
        // zone's offset. Only the mapped type turns it back into UTC digits. The key is
        // row 7 at 03:30Z, which reads 05:30 in +02:00 and would skip row 4 at 05:10Z.
        [, $next] = $this->page('/utcShifts?sort=' . $column . '&page[size]=2');
        self::assertNotNull($next);
        [, $next] = $this->page($next);
        self::assertNotNull($next);
        [$expected] = $this->page($next);
        self::assertSame(['4', '5'], $expected);

        [$shifted] = $this->page($this->withCursorKeyIn($next, $column, new \DateTimeZone('+02:00')));
        self::assertSame($expected, $shifted);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagesWalkAPlainMutableDateTimeColumn(): void
    {
        $order = ['4', '5', '2', '3', '1', '6', '7'];

        self::assertSame($order, $this->walk('/utcShifts?sort=loggedAt&page[size]=2'));
        self::assertSame(\array_reverse($order), $this->walk('/utcShifts?sort=-loggedAt&page[size]=2'));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorPagesWalkAPlainMutableDateColumn(): void
    {
        self::assertSame(['2', '4', '7', '1', '3', '5', '6'], $this->walk('/utcShifts?sort=onDay&page[size]=2'));
        self::assertSame(['6', '5', '3', '1', '7', '4', '2'], $this->walk('/utcShifts?sort=-onDay,-id&page[size]=2'));
    }

    /**
     * @return list<string>
     */
    private function filtered(string $filter, ?string $min, ?string $max): array
    {
        $query = [];
        if ($min !== null) {
            $query[] = 'filter[' . $filter . '][min]=' . \rawurlencode($min);
        }
        if ($max !== null) {
            $query[] = 'filter[' . $filter . '][max]=' . \rawurlencode($max);
        }

        [$ids, $next] = $this->page('/utcShifts?' . \implode('&', $query));
        self::assertNull($next);
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function walk(string $path): array
    {
        $ids = [];
        $pages = 0;
        $next = $path;
        while ($next !== null) {
            [$pageIds, $next] = $this->page($next);
            self::assertLessThanOrEqual(2, \count($pageIds));
            \array_push($ids, ...$pageIds);
            self::assertLessThan(10, ++$pages, 'paging must terminate');
        }

        return $ids;
    }

    /**
     * The ids on the page at `$path` and the path of the next page, if any.
     *
     * @return array{list<string>, ?string}
     */
    private function page(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        $links = $document['links'] ?? [];
        self::assertIsArray($links);
        $next = $links['next'] ?? null;
        if (\is_array($next)) {
            $next = $next['href'] ?? null;
        }
        if ($next === null) {
            return [$ids, null];
        }
        self::assertIsString($next);

        $query = \parse_url($next, \PHP_URL_QUERY);
        self::assertIsString($query);

        return [$ids, \parse_url($next, \PHP_URL_PATH) . '?' . $query];
    }

    /**
     * `$path` with its `page[after]` cursor key for `$column` rewritten as the same
     * instant in `$zone`.
     */
    private function withCursorKeyIn(string $path, string $column, \DateTimeZone $zone): string
    {
        [$route, $query] = \explode('?', $path, 2);
        \parse_str($query, $parameters);
        $page = $parameters['page'] ?? null;
        self::assertIsArray($page);
        $token = $page['after'] ?? null;
        self::assertIsString($token);

        $boundary = \json_decode((string) \base64_decode(\strtr($token, '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($boundary);
        $key = $boundary[$column] ?? null;
        self::assertIsString($key);
        self::assertStringEndsWith('+00:00', $key);
        $boundary[$column] = (new \DateTimeImmutable($key))->setTimezone($zone)->format('Y-m-d\TH:i:s.uP');

        $page['after'] = \rtrim(\strtr(\base64_encode(\json_encode($boundary, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $parameters['page'] = $page;

        return $route . '?' . \http_build_query($parameters);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchedAttributes(string $id): array
    {
        $fetched = $this->handle('/utcShifts/' . $id);
        self::assertSame(200, $fetched->getStatusCode(), (string) $fetched->getContent());
        $data = $this->decode($fetched)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function idOf(array $document): string
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function stored(string $column, string $id): mixed
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection->fetchOne(\sprintf('SELECT %s FROM tz_utc_shift WHERE id = ?', $column), [$id]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
