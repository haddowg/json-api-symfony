<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone\ShiftFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Date bounds and date writes against shifts stored as local New York time, with
 * PHP's default timezone set to New York for each test.
 *
 * The same assertions run on the in-memory provider, which compares instants, and on
 * Doctrine, whose naive `datetime` column compares wall-clock strings. A bound sent
 * in `Z` or another offset has to select the same rows on both. Shift 1 starts at
 * 16:00Z, shift 2 at 14:00Z and shift 3 at 13:00Z the next day.
 */
abstract class StorageTimezoneConformanceTestCase extends JsonApiFunctionalTestCase
{
    private string $previousTimezone;

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

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeBoundInUtcComparesTheInstantNotTheWallClock(): void
    {
        // 15:00Z is 11:00 in New York, so the 12:00 local shift is included and the
        // 10:00 one is not. Comparing 15:00 against the stored local times would drop
        // shift 1.
        self::assertSame(['1', '3'], $this->sortedIds('/shifts?filter[startsRange][min]=2026-10-01T15:00:00Z'));
        self::assertSame(['2'], $this->sortedIds('/shifts?filter[startsRange][max]=2026-10-01T15:00:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateRangeBoundWithAnotherOffsetComparesTheInstant(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/shifts?filter[startsRange][min]=' . \rawurlencode('2026-10-01T17:00:00+02:00')));
        self::assertSame(['1'], $this->sortedIds('/shifts?filter[startsRange][min]=' . \rawurlencode('2026-10-01T11:30:00-04:00') . '&filter[startsRange][max]=2026-10-01T16:00:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aBoundWithoutAnOffsetIsReadAsLocalTime(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/shifts?filter[startsRange][min]=2026-10-01T11:00:00'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aWhereFilterWithADateValueComparesTheInstant(): void
    {
        self::assertSame(['1', '3'], $this->sortedIds('/shifts?filter[startsFrom]=2026-10-01T15:00:00Z'));
        self::assertSame(['3'], $this->sortedIds('/shifts?filter[startsFrom]=2026-10-01T16:00:01Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aDateColumnComparesTheDayTheClientWrote(): void
    {
        // Moving midnight UTC into New York would make it 30 September and pull in
        // shift 2, so a date column keeps the bound's own calendar day.
        self::assertSame(['1', '3'], $this->sortedIds('/shifts?filter[dayRange][min]=2026-10-01'));
        self::assertSame(['1', '3'], $this->sortedIds('/shifts?filter[dayRange][min]=2026-10-01T00:00:00Z'));
        self::assertSame(['1', '2'], $this->sortedIds('/shifts?filter[dayRange][max]=2026-10-01'));
    }

    #[Test]
    #[Group('crud:create')]
    public function aWriteWithAnOffsetRoundTripsAsTheSameInstant(): void
    {
        $created = $this->handle('/shifts', 'POST', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-10-03T16:00:00Z', 'day' => '2026-10-03'],
        ]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $attributes = $this->attributesOf($this->decode($created));
        self::assertSame('2026-10-03T16:00:00+00:00', $attributes['startsAt'] ?? null);
        self::assertSame('2026-10-03', $attributes['day'] ?? null);

        $id = $this->idOf($this->decode($created));

        $fetched = $this->handle('/shifts/' . $id);
        self::assertSame(200, $fetched->getStatusCode(), (string) $fetched->getContent());
        self::assertSame('2026-10-03T16:00:00+00:00', $this->attributesOf($this->decode($fetched))['startsAt'] ?? null);

        // And it is found by the instant it was written with, from either side.
        self::assertSame([$id], $this->sortedIds('/shifts?filter[startsRange][min]=2026-10-03T12:00:00-04:00&filter[startsRange][max]=2026-10-03T16:00:00Z'));
    }

    #[Test]
    #[Group('crud:update')]
    public function anUpdateWithAnOffsetRoundTripsAsTheSameInstant(): void
    {
        $updated = $this->handle('/shifts/2', 'PATCH', ['data' => [
            'type' => 'shifts',
            'id' => '2',
            'attributes' => ['startsAt' => '2026-10-01T18:30:00+02:00'],
        ]]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());

        $fetched = $this->handle('/shifts/2');
        self::assertSame('2026-10-01T16:30:00+00:00', $this->attributesOf($this->decode($fetched))['startsAt'] ?? null);
        self::assertSame(['1', '2', '3'], $this->sortedIds('/shifts?filter[startsRange][min]=2026-10-01T16:00:00Z'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:errors')]
    public function anImpossibleDateBoundIsA400(): void
    {
        foreach (['2026-02-30T00:00:00Z', '2026-04-31', '2026-02-28T24:00:00Z'] as $bound) {
            $response = $this->handle('/shifts?filter[startsRange][min]=' . \rawurlencode($bound));

            self::assertSame(400, $response->getStatusCode(), $bound . ': ' . $response->getContent());
            $error = $this->firstError($this->decode($response));
            self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
            self::assertSame(['parameter' => 'filter[startsRange]'], $error['source'] ?? null);
        }
    }

    #[Test]
    #[Group('crud:create')]
    #[Group('spec:errors')]
    public function anImpossibleDateInAWriteIsA422AtTheAttribute(): void
    {
        $response = $this->handle('/shifts', 'POST', ['data' => [
            'type' => 'shifts',
            'attributes' => ['startsAt' => '2026-02-30T00:00:00Z'],
        ]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->firstError($this->decode($response));
        self::assertSame('ATTRIBUTE_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['pointer' => '/data/attributes/startsAt'], $error['source'] ?? null);
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function idOf(array $document): string
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function attributesOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
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

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);

        /** @var array<string, mixed> $error */
        return $error;
    }
}
