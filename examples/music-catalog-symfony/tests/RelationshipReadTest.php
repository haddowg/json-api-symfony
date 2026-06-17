<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relationship-read acceptance suite (backs `relationships.md`): linkage in the
 * `relationships` member with convention `self`/`related` links, the `related`
 * (`GET /{type}/{id}/{rel}`) and `relationship` (`GET …/relationships/{rel}`) read
 * endpoints, the empty to-one `data: null`, the opt-in load-state policy, and
 * compound `?include` — all over the reference Doctrine provider.
 *
 * Probes ride `tracks` (a `belongsTo album` to-one and a `belongsToMany playlists`
 * to-many opted back to eager with `withData()`, both read straight off the entity)
 * and `albums` (whose `tracks` to-many keeps the lazy default, so a lazy collection
 * emits links without forcing a fetch). The empty to-one is a freshly created `favorites` row with no
 * target (rendering `data: null`); a seeded `favorites.favoritable` (a `MorphTo`)
 * resolves its member's own serializer per related object.
 */
#[Group('spec:fetching-relationships')]
final class RelationshipReadTest extends MusicCatalogKernelTestCase
{
    private const string BASE_URI = 'https://music.example';

    // --- linkage + convention links on a resource read -----------------------

    #[Test]
    public function aToOneRelationshipRendersASingleResourceIdentifierWithConventionLinks(): void
    {
        // Track 1 belongs to album 1.
        $relationships = $this->relationshipsOf($this->fetchResource('/tracks/1'));

        $album = $relationships['album'] ?? null;
        self::assertIsArray($album);
        self::assertSame(['type' => 'albums', 'id' => '1'], $album['data'] ?? null);
        self::assertSame(
            [
                'self' => self::BASE_URI . '/tracks/1/relationships/album',
                'related' => self::BASE_URI . '/tracks/1/album',
            ],
            $album['links'] ?? null,
        );
    }

    #[Test]
    public function aToManyRelationshipRendersAListOfResourceIdentifiers(): void
    {
        // Track 1 is on the Morning Mix playlist.
        $relationships = $this->relationshipsOf($this->fetchResource('/tracks/1'));

        $playlists = $relationships['playlists'] ?? null;
        self::assertIsArray($playlists);
        self::assertSame(
            [['type' => 'playlists', 'id' => '00000000-0000-4000-8000-000000000001']],
            $this->normaliseIdentifiers($playlists['data'] ?? null),
        );
        self::assertSame(
            [
                'self' => self::BASE_URI . '/tracks/1/relationships/playlists',
                'related' => self::BASE_URI . '/tracks/1/playlists',
            ],
            $playlists['links'] ?? null,
        );
    }

    #[Test]
    public function aLoadStateOptInToManyEmitsLinksWithoutForcingAFetch(): void
    {
        // AlbumResource's `tracks` to-many is lazy by default (a to-many's per-type
        // default since core ADR 0067): on a lazy Doctrine collection it emits the
        // convention links but NO `data` member — the load-state seam reports the
        // uninitialised collection as "not loaded", so the identifiers are not
        // materialised.
        $relationships = $this->relationshipsOf($this->fetchResource('/albums/1'));

        $tracks = $relationships['tracks'] ?? null;
        self::assertIsArray($tracks);
        self::assertArrayNotHasKey('data', $tracks);
        self::assertSame(
            [
                'self' => self::BASE_URI . '/albums/1/relationships/tracks',
                'related' => self::BASE_URI . '/albums/1/tracks',
            ],
            $tracks['links'] ?? null,
        );

        // The sibling `artist` to-one (no load-state opt-in) still carries its data.
        $artist = $relationships['artist'] ?? null;
        self::assertIsArray($artist);
        self::assertSame(['type' => 'artists', 'id' => '1'], $artist['data'] ?? null);
    }

    // --- the related endpoint (full resources) -------------------------------

    #[Test]
    public function aRelatedToOneEndpointRendersASingleFullResource(): void
    {
        $document = $this->fetchDocument('/tracks/1/album');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('albums', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('OK Computer', $attributes['title'] ?? null);
    }

    #[Test]
    public function aRelatedToManyEndpointRendersAListOfFullResources(): void
    {
        // Track 1 is on Morning Mix.
        $document = $this->fetchDocument('/tracks/1/playlists');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);

        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('playlists', $first['type'] ?? null);
        $attributes = $first['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Morning Mix', $attributes['title'] ?? null);
    }

    #[Test]
    public function aRelatedToOneEndpointRendersDataNullForAnEmptyToOne(): void
    {
        // A favorite created with no target has an empty `favoritable`: the related
        // endpoint renders 200 with data:null — not a 404.
        $response = $this->handle('/favorites', 'POST', [
            'data' => ['type' => 'favorites', 'attributes' => ['favoritedAt' => '2024-06-01T00:00:00+00:00']],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $created = $this->decode($response)['data'] ?? null;
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        $document = $this->fetchDocument('/favorites/' . $id . '/favoritable');

        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRelatedToOneFilterThatExcludesTheTargetRendersDataNull(): void
    {
        // null-a-to-one-when-a-relation-filter-excludes-its-target (bundle ADR 0068):
        // the album's `artist` to-one declares a relation-scoped `filter[name]` (the
        // related `artists.name` column). On the related endpoint the filter resolves
        // against the single target — a matching name renders the artist; a mismatch
        // renders `data: null` (not a 404). Album 1 belongs to Radiohead.
        $matched = $this->fetchDocument('/albums/1/artist?filter[name]=Radiohead')['data'] ?? null;
        self::assertIsArray($matched);
        self::assertSame('artists', $matched['type'] ?? null);
        self::assertSame('1', $matched['id'] ?? null);

        $excluded = $this->fetchDocument('/albums/1/artist?filter[name]=Portishead');
        self::assertArrayHasKey('data', $excluded);
        self::assertNull($excluded['data'], 'the filter excludes the album\'s artist, so the to-one is null');
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRelationshipToOneFilterThatExcludesTheTargetRendersNullLinkage(): void
    {
        // The same exclusion on the relationship (linkage) endpoint: a matching
        // `filter[name]` carries the identifier, a mismatch nulls the linkage.
        $matched = $this->fetchDocument('/albums/1/relationships/artist?filter[name]=Radiohead');
        self::assertSame(['type' => 'artists', 'id' => '1'], $matched['data'] ?? null);

        $excluded = $this->fetchDocument('/albums/1/relationships/artist?filter[name]=Portishead');
        self::assertArrayHasKey('data', $excluded);
        self::assertNull($excluded['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPolymorphicToOneResolvesItsSerializerFromTheRelatedObject(): void
    {
        // Favorite 2 favorites album 1; favorite 3 favorites artist 2. The MorphTo
        // related endpoint resolves each member's own serializer from the resolved
        // object, so the same relation renders an `albums` resource for one favorite
        // and an `artists` resource for another.
        $album = $this->fetchDocument('/favorites/2/favoritable')['data'] ?? null;
        self::assertIsArray($album);
        self::assertSame('albums', $album['type'] ?? null);
        self::assertSame('1', $album['id'] ?? null);

        $artist = $this->fetchDocument('/favorites/3/favoritable')['data'] ?? null;
        self::assertIsArray($artist);
        self::assertSame('artists', $artist['type'] ?? null);
        self::assertSame('2', $artist['id'] ?? null);
    }

    // --- the relationship (linkage) endpoint ---------------------------------

    #[Test]
    public function aRelationshipToOneEndpointRendersASingleIdentifier(): void
    {
        $document = $this->fetchDocument('/tracks/1/relationships/album');

        self::assertSame(['type' => 'albums', 'id' => '1'], $document['data'] ?? null);
    }

    #[Test]
    public function aRelationshipToManyEndpointRendersAListOfIdentifiers(): void
    {
        // The relationship endpoint materialises the to-many even under the
        // load-state policy: album 1 owns tracks 1, 2, 3.
        $document = $this->fetchDocument('/albums/1/relationships/tracks');

        self::assertSame(
            [
                ['type' => 'tracks', 'id' => '1'],
                ['type' => 'tracks', 'id' => '2'],
                ['type' => 'tracks', 'id' => '3'],
            ],
            $this->normaliseIdentifiers($document['data'] ?? null),
        );
    }

    // --- 404 paths -----------------------------------------------------------

    #[Test]
    #[Group('spec:errors')]
    public function aMissingParentOnARelatedEndpointRendersA404(): void
    {
        $this->assertNotFound('/albums/999/artist', 'RESOURCE_NOT_FOUND');
    }

    #[Test]
    #[Group('spec:errors')]
    public function anUnknownRelationshipRendersA404(): void
    {
        $this->assertNotFound('/tracks/1/bogus', 'RELATIONSHIP_NOT_EXISTS');
    }

    // --- compound include ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aSingleResourceFetchWithIncludeRendersACompoundDocument(): void
    {
        // ?include=album,playlists populates the top-level included member.
        $document = $this->fetchDocument('/tracks/1?include=album,playlists');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $index = $this->includedIndex($included);
        self::assertArrayHasKey('albums:1', $index);
        self::assertArrayHasKey('playlists:00000000-0000-4000-8000-000000000001', $index);
        self::assertSame('OK Computer', $this->attribute($index, 'albums:1', 'title'));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchResource(string $path): array
    {
        $data = $this->fetchDocument($path)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipsOf(array $resource): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, mixed> $relationships */
        return $relationships;
    }

    /**
     * @return list<array{type: mixed, id: mixed}>
     */
    private function normaliseIdentifiers(mixed $data): array
    {
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = ['type' => $identifier['type'] ?? null, 'id' => $identifier['id'] ?? null];
        }

        return $identifiers;
    }

    private function assertNotFound(string $path, string $code): void
    {
        $response = $this->handle($path);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('404', $first['status'] ?? null);
        self::assertSame($code, $first['code'] ?? null);
    }

    /**
     * @param array<mixed> $included
     *
     * @return array<string, array<string, mixed>>
     */
    private function includedIndex(array $included): array
    {
        $index = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            /** @var array<string, mixed> $resource */
            $index[$type . ':' . $id] = $resource;
        }

        return $index;
    }

    /**
     * The named attribute of the indexed resource keyed `"{type}:{id}"`.
     *
     * @param array<string, array<string, mixed>> $index
     */
    private function attribute(array $index, string $key, string $name): mixed
    {
        $attributes = $index[$key]['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }
}
