<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Favorite;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The relationship-mutation acceptance suite (backs `relationships.md`): the
 * `PATCH`/`POST`/`DELETE …/relationships/{rel}` endpoints and the relationships
 * embedded in whole-resource writes, each applied through the reference Doctrine
 * persister's `mutateRelationship()` seam and re-read (after clearing the identity
 * map) to prove the change reached the database.
 *
 *  - to-one (`tracks.album`): `PATCH {data:{…}}` replaces, `PATCH {data:null}` clears.
 *  - to-many owning side (`tracks.playlists`): `POST` adds (idempotent), `DELETE`
 *    removes; that relation forbids full replacement, so `PATCH` is a 403.
 *  - whole-resource writes set associations through the same seam.
 *  - cardinality (`POST`/`DELETE` on a to-one) → 400; unknown rel / missing parent
 *    → 404.
 */
#[Group('spec:updating-relationships')]
final class RelationshipMutationTest extends MusicCatalogKernelTestCase
{
    private const string PLAYLIST_ID = SeedManifest::OWNED_PLAYLIST_ID;

    // --- to-one --------------------------------------------------------------

    #[Test]
    public function patchingAToOneReplacesItAndPersists(): void
    {
        // Track 1 belongs to album 1; replace with album 2.
        $response = $this->handle('/tracks/1/relationships/album', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '2'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['type' => 'albums', 'id' => '2'], $this->linkage($response));

        self::assertSame(
            ['type' => 'albums', 'id' => '2'],
            $this->linkageOf('/tracks/1/relationships/album'),
        );
    }

    #[Test]
    public function patchingAToOneWithNullDataClearsItAndPersists(): void
    {
        $response = $this->handle('/tracks/1/relationships/album', 'PATCH', ['data' => null]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull($this->linkage($response));

        self::assertNull($this->linkageOf('/tracks/1/relationships/album'));
    }

    // --- to-many (owning side) -----------------------------------------------

    #[Test]
    public function postingToAToManyAddsAMemberAndPersists(): void
    {
        // Track 3 is on no playlist; add the Morning Mix.
        $response = $this->handle('/tracks/3/relationships/playlists', 'POST', [
            'data' => [['type' => 'playlists', 'id' => self::PLAYLIST_ID]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([self::PLAYLIST_ID], $this->playlistIds($this->identifiers($response)));

        self::assertSame(
            [self::PLAYLIST_ID],
            $this->playlistIds($this->identifiersOf('/tracks/3/relationships/playlists')),
        );
    }

    #[Test]
    public function postingAnAlreadyPresentMemberIsIdempotent(): void
    {
        // Track 1 is already on Morning Mix; re-adding it does not duplicate.
        $response = $this->handle('/tracks/1/relationships/playlists', 'POST', [
            'data' => [['type' => 'playlists', 'id' => self::PLAYLIST_ID]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([self::PLAYLIST_ID], $this->playlistIds($this->identifiers($response)));
    }

    #[Test]
    public function deletingFromAToManyRemovesAMemberAndPersists(): void
    {
        // Track 1 is on Morning Mix; remove it, leaving an empty set.
        $response = $this->handle('/tracks/1/relationships/playlists', 'DELETE', [
            'data' => [['type' => 'playlists', 'id' => self::PLAYLIST_ID]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $this->playlistIds($this->identifiers($response)));

        self::assertSame([], $this->playlistIds($this->identifiersOf('/tracks/1/relationships/playlists')));
    }

    // --- whole-resource writes through the same seam -------------------------

    #[Test]
    #[Group('spec:creating-resources')]
    public function creatingAResourceWithARelationshipSetsItThroughThePersisterSeam(): void
    {
        // A whole-resource create carrying a to-one `user` in data.relationships:
        // the handler hydrates the attributes, then sets the association through the
        // persister seam — an id-only linkage resolves to a managed reference and the
        // FK is written.
        $response = $this->handle('/favorites', 'POST', [
            'data' => [
                'type' => 'favorites',
                'attributes' => ['favoritedAt' => '2024-06-01T00:00:00+00:00'],
                'relationships' => [
                    'user' => ['data' => ['type' => 'users', 'id' => '1']],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertNotSame('', $id);

        // The association persisted: read the FK straight off a freshly loaded entity
        // (the rendered linkage for the admin-only `users` type is not the witness
        // here — the database write is).
        self::assertSame('1', $this->persistedUserId($id));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function patchingAResourceReplacesItsRelationshipThroughThePersisterSeam(): void
    {
        // A whole-resource PATCH carrying data.relationships replaces track 2's album
        // (1 → 2) through the same seam as the relationship endpoints — not core's
        // scalar-id hydration onto a typed association property.
        $response = $this->handle('/tracks/2', 'PATCH', [
            'data' => [
                'type' => 'tracks',
                'id' => '2',
                'attributes' => ['title' => 'Paranoid Android (Remaster)'],
                'relationships' => [
                    'album' => ['data' => ['type' => 'albums', 'id' => '2']],
                ],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Paranoid Android (Remaster)', $attributes['title'] ?? null);

        self::assertSame(
            ['type' => 'albums', 'id' => '2'],
            $this->linkageOf('/tracks/2/relationships/album'),
        );
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function patchingAResourceWithoutRelationshipsLeavesThemUntouched(): void
    {
        // Track 1 keeps album 1 when a PATCH supplies no data.relationships.
        $response = $this->handle('/tracks/1', 'PATCH', [
            'data' => ['type' => 'tracks', 'id' => '1', 'attributes' => ['title' => 'Just a retitle']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(
            ['type' => 'albums', 'id' => '1'],
            $this->linkageOf('/tracks/1/relationships/album'),
        );
    }

    // --- mutability + cardinality + 404 gates --------------------------------

    #[Test]
    public function patchingACannotReplaceToManyIsForbidden(): void
    {
        // `tracks.playlists` declares cannotReplace(): a full PATCH replacement is a
        // 403, and the existing set is untouched.
        $response = $this->handle('/tracks/1/relationships/playlists', 'PATCH', [
            'data' => [['type' => 'playlists', 'id' => self::PLAYLIST_ID]],
        ]);

        $this->assertError($response, 403, 'FULL_REPLACEMENT_PROHIBITED');

        self::assertSame(
            [self::PLAYLIST_ID],
            $this->playlistIds($this->identifiersOf('/tracks/1/relationships/playlists')),
        );
    }

    #[Test]
    public function postingToAToOneIsACardinalityError(): void
    {
        $response = $this->handle('/tracks/1/relationships/album', 'POST', [
            'data' => [['type' => 'albums', 'id' => '2']],
        ]);

        $this->assertError($response, 400, 'RELATIONSHIP_TYPE_INAPPROPRIATE');
    }

    #[Test]
    #[Group('spec:errors')]
    public function mutatingAnUnknownRelationshipIs404(): void
    {
        $response = $this->handle('/tracks/1/relationships/bogus', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '2'],
        ]);

        $this->assertError($response, 404, 'RELATIONSHIP_NOT_EXISTS');
    }

    #[Test]
    #[Group('spec:errors')]
    public function mutatingARelationshipOnAMissingParentIs404(): void
    {
        $response = $this->handle('/tracks/999/relationships/album', 'PATCH', [
            'data' => ['type' => 'albums', 'id' => '2'],
        ]);

        $this->assertError($response, 404, 'RESOURCE_NOT_FOUND');
    }

    // --- helpers -------------------------------------------------------------

    private function linkage(Response $response): mixed
    {
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response)['data'] ?? null;
    }

    private function linkageOf(string $path): mixed
    {
        return $this->fetchDocument($path)['data'] ?? null;
    }

    /**
     * Clears the Doctrine identity map so a re-read is a genuine round-trip to the
     * store, not a managed instance served from the unit of work.
     */
    private function detachPersistedState(): void
    {
        $this->entityManager()->clear();
    }

    /**
     * The id of the `user` a favorite was persisted with, read off a freshly loaded
     * entity (the database is the witness, not the rendered linkage).
     */
    private function persistedUserId(string $favoriteId): ?string
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $favorite = $entityManager->find(Favorite::class, $favoriteId);
        self::assertInstanceOf(Favorite::class, $favorite);

        // The user id is a store-provided integer; compare it as the wire string.
        return $favorite->user?->id === null ? null : (string) $favorite->user->id;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $this->detachPersistedState();
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiers(Response $response): array
    {
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->reduceIdentifiers($this->decode($response)['data'] ?? null);
    }

    /**
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiersOf(string $path): array
    {
        return $this->reduceIdentifiers($this->fetchDocument($path)['data'] ?? null);
    }

    /**
     * @return list<array{type: mixed, id: mixed}>
     */
    private function reduceIdentifiers(mixed $data): array
    {
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = ['type' => $identifier['type'] ?? null, 'id' => $identifier['id'] ?? null];
        }

        return $identifiers;
    }

    /**
     * @param list<array{type: mixed, id: mixed}> $identifiers
     *
     * @return list<string>
     */
    private function playlistIds(array $identifiers): array
    {
        $ids = [];
        foreach ($identifiers as $identifier) {
            self::assertSame('playlists', $identifier['type']);
            $id = $identifier['id'];
            self::assertIsString($id);
            $ids[] = $id;
        }

        \sort($ids);

        return $ids;
    }

    private function assertError(Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame((string) $status, $first['status'] ?? null);
        self::assertSame($code, $first['code'] ?? null);
    }
}
