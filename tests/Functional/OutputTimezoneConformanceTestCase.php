<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server output timezone (`json_api.output_timezone`, core ADR 0143), run against
 * the in-memory provider ({@see InMemoryOutputTimezoneTest}) and the Doctrine provider
 * ({@see DoctrineOutputTimezoneTest}).
 *
 * Every stored value is Paris local time. The default server renders in UTC, the
 * `tokyo` server in Asia/Tokyo, and the `raw` server sets `''`, so its values keep
 * their Paris offset. A `DateTime` takes the server zone; a `DateTime` with its own
 * `outputTimezone()` keeps it; `Date` and `Time` never move.

 */
abstract class OutputTimezoneConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:document-resource-objects')]
    public function aDateTimeAttributeRendersInTheServerZone(): void
    {
        self::assertSame('2024-06-01T19:30:00+00:00', $this->attributes('/gigs/1')['startsAt'] ?? null);
    }

    #[Test]
    #[Group('spec:document-resource-objects')]
    public function aFieldsOwnOutputTimezoneWinsOverTheServerZone(): void
    {
        self::assertSame('2024-05-01T04:00:00-04:00', $this->attributes('/gigs/1')['announcedAt'] ?? null);
    }

    #[Test]
    #[Group('spec:document-resource-objects')]
    public function dateAndTimeAttributesIgnoreTheServerZone(): void
    {
        $attributes = $this->attributes('/gigs/1');

        // Converted to UTC these would read 2024-05-31 and 18:00:00.
        self::assertSame('2024-06-01', $attributes['playedOn'] ?? null);
        self::assertSame('20:00:00', $attributes['doorsAt'] ?? null);
    }

    #[Test]
    #[Group('spec:document-resource-objects')]
    public function aCollectionRendersEachMemberInTheServerZone(): void
    {
        $document = $this->fetchDocument('/gigs');
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $first = $data[0] ?? null;
        self::assertIsArray($first);
        $attributes = $first['attributes'] ?? null;
        self::assertIsArray($attributes);

        self::assertSame('2024-06-01T19:30:00+00:00', $attributes['startsAt'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludedResourceRendersInTheServerZone(): void
    {
        $document = $this->fetchDocument('/gigs/1?include=venue');
        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertCount(1, $included);
        $venue = $included[0] ?? null;
        self::assertIsArray($venue);
        self::assertSame('venues', $venue['type'] ?? null);
        $attributes = $venue['attributes'] ?? null;
        self::assertIsArray($attributes);

        self::assertSame('2010-03-15T08:00:00+00:00', $attributes['openedAt'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedResourceRendersInTheServerZone(): void
    {
        self::assertSame('2010-03-15T08:00:00+00:00', $this->attributes('/gigs/1/venue')['openedAt'] ?? null);
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function aNamedServerRendersInItsOwnZone(): void
    {
        self::assertSame('2024-06-02T04:30:00+09:00', $this->attributes('/tokyo/gig-listings/1')['startsAt'] ?? null);
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function aNamedServerWithAnEmptyZoneLeavesValuesInTheirOwnZone(): void
    {
        self::assertSame('2024-06-01T21:30:00+02:00', $this->attributes('/raw/raw-gigs/1')['startsAt'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(string $path): array
    {
        $data = $this->fetchDocument($path)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }
}
