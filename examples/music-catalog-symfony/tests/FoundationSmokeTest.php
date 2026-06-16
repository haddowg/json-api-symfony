<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * The Foundation smoke test: proves the example app kernel boots fully and the
 * integration backbone the additive slices build on is live. It asserts the
 * Doctrine provider serves `GET /albums` on the default server, the serializer
 * override serves `GET /tracks` (carrying the bound-constructor `meta` marker),
 * and the named `admin` server resolves `GET /admin/albums`. The full per-page
 * conformance suites land in the next phase.
 */
#[Group('spec:foundation')]
final class FoundationSmokeTest extends MusicCatalogKernelTestCase
{
    public function testGetAlbumsReturnsASpecCompliantCollectionOverDoctrine(): void
    {
        $response = $this->handle('/albums');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/vnd.api+json', (string) $response->headers->get('Content-Type'));

        $document = $this->decode($response);
        self::assertArrayHasKey('data', $document);
        self::assertIsList($document['data']);
        self::assertNotEmpty($document['data']);

        $first = $document['data'][0];
        self::assertIsArray($first);
        self::assertSame('albums', $first['type'] ?? null);
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('attributes', $first);
        self::assertIsArray($first['attributes']);
        self::assertArrayHasKey('title', $first['attributes']);
    }

    public function testGetTracksRendersThroughTheOverrideSerializer(): void
    {
        $response = $this->handle('/tracks');

        self::assertSame(200, $response->getStatusCode());

        $document = $this->decode($response);
        self::assertArrayHasKey('data', $document);
        self::assertIsList($document['data']);
        self::assertNotEmpty($document['data']);

        $first = $document['data'][0];
        self::assertIsArray($first);
        self::assertSame('tracks', $first['type'] ?? null);
        self::assertIsArray($first['attributes'] ?? null);
        // The override serializer derives `displayTitle` across two columns.
        self::assertArrayHasKey('displayTitle', $first['attributes']);
        // The override serializer's bound constructor dependency surfaces in meta —
        // proving the bundle resolved it through the container with its dependency.
        self::assertIsArray($first['meta'] ?? null);
        self::assertSame('music-catalog', $first['meta']['served_by'] ?? null);
    }

    public function testAdminServerResolvesAlbums(): void
    {
        $response = $this->handle('/admin/albums');

        self::assertSame(200, $response->getStatusCode());

        $document = $this->decode($response);
        self::assertArrayHasKey('data', $document);
        self::assertIsList($document['data']);
        self::assertNotEmpty($document['data']);

        $first = $document['data'][0];
        self::assertIsArray($first);
        self::assertSame('albums', $first['type'] ?? null);
    }
}
