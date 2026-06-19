<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The example app's OpenAPI witness: the `json_api.openapi` config
 * (`config/packages/json_api.yaml`) plus the `jsonapi_openapi` route import make the
 * catalogue self-describing. This suite proves the document **serves** with the
 * configured surface — the info block, the `Catalog`/`Library` tags, the `bearer`
 * security scheme, and the reusable, *described* `AlbumStatus` enum component — and,
 * via {@see SchemaConformanceTrait}, that the generated schemas actually **describe the
 * real catalogue responses** (the round-trip guarantee, design §8/G6).
 *
 * `expose_in_prod: true` in the example config registers the docs routes even though
 * the kernel boots `debug=false`, so `GET /docs.json` resolves here.
 */
#[Group('spec:openapi')]
final class OpenApiDocsTest extends MusicCatalogKernelTestCase
{
    use SchemaConformanceTrait;

    #[Test]
    public function theDocumentServesWithTheConfiguredInfoAndComponents(): void
    {
        $response = $this->handle('/docs.json');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        // The info block from config.
        self::assertSame('3.1.0', $document['openapi'] ?? null);
        $info = $this->nested($document, 'info');
        self::assertSame('Music Catalog API', $info['title'] ?? null);
        self::assertSame('1.0.0', $info['version'] ?? null);

        // The configured tag definitions, in config order.
        $tagNames = \array_column($this->nested($document, 'tags'), 'name');
        self::assertContains('Catalog', $tagNames);
        self::assertContains('Library', $tagNames);

        // The bearer security scheme matching the example firewall.
        self::assertArrayHasKey('bearer', $this->nested($document, 'components', 'securitySchemes'));

        // The backed-enum `AlbumStatus` is hoisted into a reusable named component
        // carrying its three described cases.
        $albumStatus = $this->nested($document, 'components', 'schemas', 'AlbumStatus');
        self::assertSame(['upcoming', 'released', 'withdrawn'], $albumStatus['enum'] ?? null);
    }

    #[Test]
    public function theAlbumOperationsCarryTheCatalogTagAndASecuredOperationCarriesSecurity(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        // The albums operations group under the explicit Catalog tag.
        self::assertContains('Catalog', $this->nested($document, 'paths', '/albums', 'get', 'tags'));

        // PlaylistResource::securityDelete gates DELETE /playlists/{id}, so that op
        // carries a security requirement.
        self::assertArrayHasKey('security', $this->nested($document, 'paths', '/playlists/{id}', 'delete'));
    }

    #[Test]
    public function aSingleAlbumResponseMatchesItsGeneratedSchema(): void
    {
        // /albums/1 default-includes its artist, so this is a compound document — the
        // generated single-document envelope describes the `included` member too.
        $response = $this->handle('/albums/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single);
    }

    #[Test]
    public function theAlbumCollectionAndRelationshipResponsesMatchTheirGeneratedSchemas(): void
    {
        $this->assertResponseMatchesGeneratedSchema($this->handle('/albums'), 'albums', SchemaDocumentKind::Collection);
        $this->assertResponseMatchesGeneratedSchema($this->handle('/albums/1/artist'), 'albums', SchemaDocumentKind::Related, 'artist');
        $this->assertResponseMatchesGeneratedSchema($this->handle('/albums/1/relationships/tracks'), 'albums', SchemaDocumentKind::Relationship, 'tracks');
    }

    #[Test]
    public function theUiViewerServes(): void
    {
        $response = $this->handle('/docs');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('swagger', \strtolower((string) $response->getContent()));
    }

    /**
     * Narrows a nested array path, asserting each level is an array so the deeper offset
     * access PHPStan sees stays typed rather than `mixed`.
     *
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
}
