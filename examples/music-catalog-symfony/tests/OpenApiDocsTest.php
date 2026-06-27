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
    #[Group('spec:fetching-filtering')]
    public function theConvenienceFiltersProjectTheirParametersAndDescriptions(): void
    {
        $document = $this->decode($this->handle('/docs.json'));
        $parameters = $this->nested($document, 'paths', '/albums', 'get', 'parameters');

        // A Contains filter surfaces its strategy description on a plain string schema.
        $title = $this->parameterNamed($parameters, 'filter[title]');
        self::assertSame('Matches values containing the given substring.', $title['description'] ?? null);

        // A Range filter is a deepObject parameter with a {min, max} object value schema.
        $rating = $this->parameterNamed($parameters, 'filter[rating]');
        self::assertSame('deepObject', $rating['style'] ?? null);
        self::assertTrue($rating['explode'] ?? null);
        self::assertSame(
            'Matches values within the given inclusive numeric range (min/max, either optional).',
            $rating['description'] ?? null,
        );
        $ratingSchema = $this->nested($rating, 'schema');
        self::assertSame('object', $ratingSchema['type'] ?? null);
        self::assertArrayHasKey('min', $this->nested($ratingSchema, 'properties'));
        self::assertArrayHasKey('max', $this->nested($ratingSchema, 'properties'));

        // A DateRange filter is a deepObject whose bounds are string/date-time.
        $releasedAt = $this->parameterNamed($parameters, 'filter[releasedAt]');
        self::assertSame('deepObject', $releasedAt['style'] ?? null);
        self::assertTrue($releasedAt['explode'] ?? null);
        $minBound = $this->nested($releasedAt, 'schema', 'properties', 'min');
        self::assertSame('date-time', $minBound['format'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotRelationAdvertisesItsAutoDerivedPivotSortTokens(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        // orderedTracks is a belongsToMany with `position`/`weight` pivot fields. The
        // auto-derived pivot sort vocabulary (honoured at runtime via the pivot-aware
        // provider) is advertised on BOTH the related and relationship endpoints, just as
        // the author-declared pivot FILTERS are.
        foreach (['/playlists/{id}/orderedTracks', '/playlists/{id}/relationships/orderedTracks'] as $path) {
            $parameters = $this->nested($document, 'paths', $path, 'get', 'parameters');
            $enum = $this->nested($this->parameterNamed($parameters, 'sort'), 'schema', 'items')['enum'] ?? [];
            self::assertIsArray($enum);
            self::assertContains('position', $enum, $path);
            self::assertContains('weight', $enum, $path);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelationTypesItsLinkageMetaPivot(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        // orderedTracks (belongsToMany → tracks) renders its `position`/`weight`/`addedAt`
        // pivot fields under each linkage identifier's `meta.pivot` at runtime; the
        // projection types them — on BOTH the embedded relationship object and the
        // relationship document (the latter shared by the read response and the
        // relationship-mutation request body, so a generated client can read and write
        // the pivot). The linkage is an `allOf` of the related identifier `$ref` plus the
        // typed `meta.pivot`.
        foreach (['PlaylistsOrderedTracksRelationship', 'PlaylistsOrderedTracksRelationshipDocument'] as $component) {
            $identifier = $this->nested($document, 'components', 'schemas', $component, 'properties', 'data', 'items');
            self::assertArrayHasKey('allOf', $identifier, $component);

            $base = $this->nested($identifier, 'allOf', '0');
            self::assertSame('#/components/schemas/TracksResourceIdentifier', $base['$ref'] ?? null, $component);

            $props = $this->nested($identifier, 'allOf', '1', 'properties', 'meta', 'properties', 'pivot', 'properties');
            self::assertSame('integer', $this->nested($props, 'position')['type'] ?? null, $component);
            self::assertSame('integer', $this->nested($props, 'weight')['type'] ?? null, $component);

            $addedAt = $this->nested($props, 'addedAt');
            self::assertSame('string', $addedAt['type'] ?? null, $component);
            self::assertSame('date-time', $addedAt['format'] ?? null, $component);
        }
    }

    #[Test]
    public function aRequireClientIdTypeMarksTheCreateIdRequired(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        // genres declares Id::make()->requireClientId(): the create body makes `data.id`
        // present AND required (a create without it is a 403 at runtime), so a client
        // generated against the document sends the id the runtime demands.
        $data = $this->nested($document, 'components', 'schemas', 'GenresCreateRequest', 'properties', 'data');
        self::assertSame('string', $this->nested($data, 'properties', 'id')['type'] ?? null);
        self::assertContains('id', $this->nested($data, 'required'));
    }

    #[Test]
    public function aPublicSecurityBooleanOverridesTheDocumentDefault(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        // ArtistResource declares `securityRead: false` + `securityList: false`: BOTH
        // reads are documented PUBLIC — an operation-level `security: []` overriding the
        // document-level `bearer` default, and NO 401.
        $read = $this->nested($document, 'paths', '/artists/{id}', 'get');
        self::assertSame([], $read['security'] ?? null, 'a public single read carries security: [] (no auth)');
        self::assertArrayNotHasKey('401', $this->nested($read, 'responses'));

        $list = $this->nested($document, 'paths', '/artists', 'get');
        self::assertSame([], $list['security'] ?? null, 'a public collection read carries security: [] (no auth)');
        self::assertArrayNotHasKey('401', $this->nested($list, 'responses'));

        // A write on the same type carries no public override, so it INHERITS the
        // document-level bearer default and advertises 401 (Tier 1) — the contrast that
        // shows the reads were deliberately opted out.
        $create = $this->nested($document, 'paths', '/artists', 'post');
        self::assertArrayNotHasKey('security', $create, 'a write has no per-op override — it inherits the document default');
        self::assertArrayHasKey('401', $this->nested($create, 'responses'));
    }

    #[Test]
    public function theUiViewerServes(): void
    {
        $response = $this->handle('/docs');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('swagger', \strtolower((string) $response->getContent()));
    }

    /**
     * Finds the OpenAPI parameter object with the given `name` in a parameter list.
     *
     * @param array<array-key, mixed> $parameters
     *
     * @return array<array-key, mixed>
     */
    private function parameterNamed(array $parameters, string $name): array
    {
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail(\sprintf('No parameter named "%s" in the operation.', $name));
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
