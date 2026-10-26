<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declared resource links (core ADR 0178), run against the in-memory provider
 * ({@see InMemoryDeclaredLinksTest}) and the Doctrine provider
 * ({@see DoctrineDeclaredLinksTest}) on the output-timezone kernels.
 *
 * `venues` declares a `map` link object with a relative href and a `DateTime` meta
 * member, a bare `website` link, and a `tickets` link whose href is `null` for an open
 * venue. The default server renders in UTC under `https://example.test`, the `tokyo`
 * server in Asia/Tokyo under `https://example.test/tokyo`.
 */
#[Group('spec:document-links')]
abstract class DeclaredLinksConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const array MAP = [
        'href' => 'https://example.test/venues/1/map',
        'title' => 'Venue map',
        'meta' => ['openedAt' => '2010-03-15T08:00:00+00:00'],
    ];

    #[Test]
    public function fetchOneRendersEachDeclaredLink(): void
    {
        $links = $this->linksOf($this->data('/venues/1'));

        self::assertSame(self::MAP, $links['map'] ?? null);
        self::assertSame('https://venues.example.test/1', $links['website'] ?? null);
    }

    #[Test]
    public function aNullHrefLeavesTheLinkOut(): void
    {
        $links = $this->linksOf($this->data('/venues/1'));

        self::assertArrayNotHasKey('tickets', $links);
        self::assertArrayHasKey('self', $links);
    }

    #[Test]
    public function aCollectionMemberRendersItsDeclaredLinks(): void
    {
        $data = $this->data('/venues');
        $first = $data[0] ?? null;
        self::assertIsArray($first);

        self::assertSame(self::MAP, $this->linksOf($first)['map'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludedResourceRendersItsDeclaredLinks(): void
    {
        $included = $this->fetchDocument('/gigs/1?include=venue')['included'] ?? null;
        self::assertIsArray($included);
        $venue = $included[0] ?? null;
        self::assertIsArray($venue);

        self::assertSame(self::MAP, $this->linksOf($venue)['map'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedResourceRendersItsDeclaredLinks(): void
    {
        self::assertSame(self::MAP, $this->linksOf($this->data('/gigs/1/venue'))['map'] ?? null);
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function aNamedServerResolvesTheHrefAndMetaInItsOwnBaseAndZone(): void
    {
        self::assertSame([
            'href' => 'https://example.test/tokyo/venues/1/map',
            'title' => 'Venue map',
            'meta' => ['openedAt' => '2010-03-15T17:00:00+09:00'],
        ], $this->linksOf($this->data('/tokyo/venues/1'))['map'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theResourceLinksSchemaNamesEachDeclaredLink(): void
    {
        $document = $this->openApi();
        $links = $this->nested($document, 'components', 'schemas', 'VenuesResourceLinks', 'properties');

        self::assertSame(['self', 'map', 'website', 'tickets'], \array_keys($links));
        $map = $this->nested($links, 'map');
        self::assertSame(['href', 'title', 'meta'], $map['required'] ?? null);
        self::assertSame('date-time', $this->nested($map, 'properties', 'meta', 'properties', 'openedAt')['format'] ?? null);

        self::assertSame(14, $this->nested($document, 'info', 'x-generator')['contract'] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function openApi(): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        $document = \json_decode((string) \json_encode($factory->forServer()->toArray()), true);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function nested(array $data, string ...$keys): array
    {
        $current = $data;
        foreach ($keys as $key) {
            self::assertIsArray($current);
            self::assertArrayHasKey($key, $current);
            $current = $current[$key];
        }
        self::assertIsArray($current);

        return $current;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function data(string $path): array
    {
        $data = $this->fetchDocument($path)['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<array-key, mixed> $resource
     *
     * @return array<array-key, mixed>
     */
    protected function linksOf(array $resource): array
    {
        $links = $resource['links'] ?? null;
        self::assertIsArray($links);

        return $links;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }
}
