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
    public function theCompositeAttributeTypesProjectTheirCompositeSchemas(): void
    {
        // The `releases` showcase carries all three composite kinds; each projects
        // its combinator keyword into the generated attribute schema.
        $document = $this->decode($this->handle('/docs.json'));
        $attributes = $this->nested($document, 'components', 'schemas', 'ReleasesAttributes', 'properties');

        // OneOf → `oneOf` + `discriminator`, each branch carrying the
        // discriminator as a `const` (plus a null branch — the field is nullable).
        $format = $this->nested($attributes, 'format');
        self::assertSame('medium', $this->nested($format, 'discriminator')['propertyName'] ?? null);
        $branches = $this->nested($format, 'oneOf');
        self::assertCount(4, $branches);
        $media = [];
        foreach ($branches as $branch) {
            self::assertIsArray($branch);
            $properties = $branch['properties'] ?? null;
            if (!\is_array($properties)) {
                continue; // the null branch — the field is nullable
            }
            $medium = $properties['medium'] ?? null;
            self::assertIsArray($medium);
            $media[] = $medium['const'] ?? null;
        }
        self::assertSame(['vinyl', 'cd', 'digital'], $media);

        // Obj → a typed nested object schema.
        $packaging = $this->nested($attributes, 'packaging');
        self::assertSame(['object', 'null'], $packaging['type'] ?? null);
        self::assertSame(40, $this->nested($packaging, 'properties', 'material')['maxLength'] ?? null);

        // Shape → the combinator contributed to the field's schema; both fields
        // are nullable, so each combinator also admits null explicitly (an
        // anyOf gains a null branch, an allOf hoists into anyOf: [null, {allOf}]).
        $availabilityBranches = $this->nested($attributes, 'availability', 'anyOf');
        self::assertCount(3, $availabilityBranches);
        self::assertContains(['type' => 'null'], $availabilityBranches);

        $dimensions = $this->nested($attributes, 'dimensions');
        self::assertArrayNotHasKey('allOf', $dimensions);
        $dimensionsBranches = $this->nested($dimensions, 'anyOf');
        self::assertCount(2, $dimensionsBranches);
        self::assertSame(['type' => 'null'], $dimensionsBranches[0] ?? null);
        self::assertCount(2, $this->nested($dimensions, 'anyOf', '1', 'allOf'));

        // On create, each OneOf branch requires its discriminator.
        $createBranches = $this->nested($document, 'components', 'schemas', 'ReleasesCreateAttributes', 'properties', 'format', 'oneOf');
        $vinyl = $createBranches[0] ?? null;
        self::assertIsArray($vinyl);
        $required = $vinyl['required'] ?? null;
        self::assertIsArray($required);
        self::assertContains('medium', $required);
        self::assertContains('rpm', $required);
    }

    #[Test]
    public function aReleaseResponseMatchesItsGeneratedSchema(): void
    {
        // The seeded vinyl release, with its album included — the composite values
        // served from their json columns validate against the projected composite
        // schemas (oneOf variant, typed object, Shape'd maps).
        $response = $this->handle('/releases/1?include=album');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'releases', SchemaDocumentKind::Single);
        $this->assertResponseMatchesGeneratedSchema($this->handle('/releases'), 'releases', SchemaDocumentKind::Collection);
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function theDefaultServerPrunesIncludePathsToTypesItCannotSerialize(): void
    {
        // `playlists.owner` targets the `users` type, which is admin-only
        // (`server: 'admin'`). On the default server `users` is unserializable, so
        // `?include=owner` could hydrate nothing — the projection prunes it (D45),
        // while include paths to default-serializable types remain.
        $document = $this->decode($this->handle('/docs.json'));
        $include = $this->parameterNamed($this->nested($document, 'paths', '/playlists', 'get', 'parameters'), 'include');
        $enum = $this->nested($include, 'schema', 'items', 'enum');

        self::assertNotContains('owner', $enum, 'include=owner reaches the admin-only users type and must be pruned on the default server');
        self::assertContains('tracks', $enum);
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
    #[Group('spec:fetching-pagination')]
    public function theCursorPaginatedUsersCollectionProjectsTheKeysetPageVocabulary(): void
    {
        // `UserResource::pagination()` pins a CursorPaginator — the catalogue's sole
        // cursor (keyset) witness — so the `users` primary collection's single `page`
        // deepObject parameter (ADR 0130) carries the keyset object schema: the opaque
        // `page[after]`/`page[before]` cursor tokens plus `page[size]`, and NOT the
        // `number` of the page-based default. `users` is admin-only, so this rides the
        // `admin` server's document; the path keys are unprefixed (the `/admin` mount
        // lives in the server URL).
        $document = $this->decode($this->handle('/admin/docs.json'));

        $userParams = $this->nested($document, 'paths', '/users', 'get', 'parameters');
        $userNames = \array_column($userParams, 'name');
        self::assertContains('page', $userNames);
        $userPageIndex = (string) (int) \array_search('page', $userNames, true);
        self::assertSame(
            ['after', 'before', 'size'],
            \array_keys($this->nested($userParams, $userPageIndex, 'schema', 'properties')),
        );

        // The cursor projection is PER-RESOURCE, not server-wide: `albums` is shared onto
        // the admin server too and keeps the page-based `number`/`size` keys on the SAME
        // document, so switching `users` to cursor left every other collection untouched.
        $albumParams = $this->nested($document, 'paths', '/albums', 'get', 'parameters');
        $albumPageIndex = (string) (int) \array_search('page', \array_column($albumParams, 'name'), true);
        self::assertSame(
            ['number', 'size'],
            \array_keys($this->nested($albumParams, $albumPageIndex, 'schema', 'properties')),
        );
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theJsonSchemasServeAsAnAggregateKeyedByType(): void
    {
        $response = $this->handle('/schemas.json');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        // The aggregate is the per-type standalone JSON Schema 2020-12 documents keyed by
        // JSON:API type — a single fetch the client codegen consumes for its validation
        // seam (bundle ADR 0101). Each is self-contained: a `$id` and a `type` const.
        $aggregate = $this->decode($response);
        foreach (['albums', 'tracks', 'playlists'] as $type) {
            $schema = $this->nested($aggregate, $type);
            self::assertSame('urn:jsonapi:schema:' . $type, $schema['$id'] ?? null, $type);
            self::assertSame($type, $this->nested($schema, 'properties', 'type')['const'] ?? null, $type);
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
