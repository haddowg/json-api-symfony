<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The polymorphic-endpoint witness over Doctrine (seams 2 + 3; backs
 * `relationships.md`):
 *
 *  - **`MorphTo` to-one — the FIRST Doctrine functional witness** (seam 3):
 *    `favorites.favoritable` points at a track, album, or artist; the
 *    {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\FavoriteProvider}
 *    resolves the target across per-type repositories from the entity's
 *    `targetType`/`targetId` pair, and the serializer is resolved from the actual
 *    related object — so favorite 1 renders a `tracks`, favorite 2 an `albums`,
 *    favorite 3 an `artists`. A favorite with no target renders `data: null`.
 *  - **`MorphToMany` via the NET-NEW {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\LibraryItemsProvider}**
 *    (seam 2 polymorphic half): `libraries.items` is a mixed collection the
 *    Doctrine provider throws on; the custom provider resolves the mixed members
 *    (track 1, album 2, artist 1) across repositories, rendered through a
 *    `PolymorphicSerializer` that discriminates each member by its own type.
 *
 * The seed wires three favorites (track 2 / album 1 / artist 2) and library 1's
 * mixed items, mirroring core's in-memory app so the two render the same shapes.
 */
#[Group('spec:fetching-relationships')]
final class PolymorphicTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aMorphToToOneResolvesItsSerializerFromATrackTarget(): void
    {
        $data = $this->fetch('/favorites/1/favoritable');
        self::assertSame('tracks', $data['type'] ?? null);
        self::assertSame('2', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Paranoid Android', $attributes['title'] ?? null);
    }

    #[Test]
    public function aMorphToToOneResolvesItsSerializerFromAnAlbumTarget(): void
    {
        // Favorite 2 targets an album, so the to-one serializer must be resolved from
        // the object, not relatedTypes()[0] (which is 'tracks').
        $data = $this->fetch('/favorites/2/favoritable');
        self::assertSame('albums', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
    }

    #[Test]
    public function aMorphToToOneResolvesItsSerializerFromAnArtistTarget(): void
    {
        $data = $this->fetch('/favorites/3/favoritable');
        self::assertSame('artists', $data['type'] ?? null);
        self::assertSame('2', $data['id'] ?? null);
    }

    #[Test]
    public function aMorphToRelationshipEndpointRendersTheCorrectIdentifierType(): void
    {
        // The linkage endpoint carries the resolved member's identifier (type + id).
        $document = $this->decode($this->handle('/favorites/2/relationships/favoritable'));
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('albums', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
    }

    #[Test]
    public function aMorphToToManyRendersMixedMemberTypesAcrossRepositories(): void
    {
        // The Doctrine provider throws on this polymorphic to-many; the custom
        // provider resolves the mixed members (track 1, album 2, artist 1) and the
        // PolymorphicSerializer discriminates each by its own type.
        $members = $this->members($this->decode($this->handle('/libraries/1/items')));

        self::assertSame(
            [['tracks', '1'], ['albums', '2'], ['artists', '1']],
            $members,
        );
    }

    #[Test]
    public function aMorphToToManyRelationshipEndpointRendersMixedIdentifiers(): void
    {
        // The linkage endpoint reads the resolved mixed list straight off the parent
        // (the provider populated it on the library fetch).
        $data = $this->decode($this->handle('/libraries/1/relationships/items'))['data'] ?? null;
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = [$identifier['type'] ?? null, $identifier['id'] ?? null];
        }

        self::assertSame([['tracks', '1'], ['albums', '2'], ['artists', '1']], $identifiers);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aMorphToToManyIncludeRendersMixedIncludedResources(): void
    {
        $included = $this->decode($this->handle('/libraries/1?include=items'))['included'] ?? null;
        self::assertIsArray($included);

        $byKey = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $byKey[] = $type . ':' . $id;
        }

        self::assertContains('tracks:1', $byKey);
        self::assertContains('albums:2', $byKey);
        self::assertContains('artists:1', $byKey);
    }

    /**
     * Fetches `$path`, asserts a 200 JSON:API response, and returns the primary
     * `data` object (a to-one related resource).
     *
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The document's primary collection members as `[type, id]` pairs, in order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{0: string, 1: string}>
     */
    private function members(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $members = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $members[] = [$type, $id];
        }

        return $members;
    }
}
