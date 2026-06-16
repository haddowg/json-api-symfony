<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The declarative-authorization acceptance suite (backs `docs/authorization.md`):
 * the example secures `playlists` with two `#[AsJsonApiResource(security: …)]`
 * expressions, and the bundle's built-in `ResourceSecuritySubscriber` evaluates
 * them at the lifecycle hooks behind the example's HTTP-Basic firewall
 * (`config/packages/security.yaml`).
 *
 *  - `securityDelete: "is_granted('ROLE_ADMIN')"` — only `admin` may delete a
 *    playlist; a `ROLE_USER` is forbidden.
 *  - `securityUpdate: "is_granted('EDIT', object)"` — only the playlist's *owner*
 *    may update it, via {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Security\PlaylistOwnerVoter};
 *    a non-owner is forbidden, an unauthenticated client is `401`.
 *
 * It proves: the owner may update / a non-owner is `403` / an unauthenticated
 * request is `401`; an admin may delete / a `ROLE_USER` is `403`; a denial aborts
 * **before** persistence (the store is unchanged); an unsecured type (`tracks`) is
 * ungated even unauthenticated; and the `403`/`401` render as JSON:API error
 * documents.
 */
#[Group('spec:crud')]
final class AuthorizationTest extends MusicCatalogKernelTestCase
{
    /** The seeded "Morning Mix" playlist (owned by ada@example.com) — a UUID PK. */
    private const string OWNED_PLAYLIST_ID = '00000000-0000-4000-8000-000000000001';

    private const array ADMIN = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'pass'];

    private const array OWNER = ['PHP_AUTH_USER' => 'ada@example.com', 'PHP_AUTH_PW' => 'pass'];

    private const array NON_OWNER = ['PHP_AUTH_USER' => 'mallory@example.com', 'PHP_AUTH_PW' => 'pass'];

    // --- securityUpdate: is_granted('EDIT', object) (the owner Voter) ----------

    #[Test]
    public function theOwnerMayUpdateTheirPlaylist(): void
    {
        $response = $this->handle('/playlists/' . self::OWNED_PLAYLIST_ID, 'PATCH', [
            'data' => [
                'type' => 'playlists',
                'id' => self::OWNED_PLAYLIST_ID,
                'attributes' => ['title' => 'Evening Mix'],
            ],
        ], self::OWNER);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('Evening Mix', $this->titleOf($response));
    }

    #[Test]
    public function aNonOwnerIsForbiddenToUpdateAndNothingChanges(): void
    {
        $response = $this->handle('/playlists/' . self::OWNED_PLAYLIST_ID, 'PATCH', [
            'data' => [
                'type' => 'playlists',
                'id' => self::OWNED_PLAYLIST_ID,
                'attributes' => ['title' => 'Hijacked'],
            ],
        ], self::NON_OWNER);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->assertJsonApiError($response, '403', 'Forbidden');

        // The denial aborted before the persister ran: the title is untouched.
        self::assertSame('Morning Mix', $this->titleOf($this->handle('/playlists/' . self::OWNED_PLAYLIST_ID)));
    }

    #[Test]
    public function anUnauthenticatedUpdateIsUnauthorized(): void
    {
        // No credentials: authentication would unlock the operation, so a denial is a
        // 401, not a 403 (the AuthenticationException the firewall surfaces is mapped).
        $response = $this->handle('/playlists/' . self::OWNED_PLAYLIST_ID, 'PATCH', [
            'data' => [
                'type' => 'playlists',
                'id' => self::OWNED_PLAYLIST_ID,
                'attributes' => ['title' => 'Anon'],
            ],
        ]);

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        $this->assertJsonApiError($response, '401', 'Unauthorized');
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aNonOwnerIsForbiddenToMutateAPlaylistRelationship(): void
    {
        // `securityUpdate` also gates relationship mutation — the subject is the
        // parent playlist, not the related track. A non-owner's DELETE against
        // Morning Mix's `tracks` relationship is denied by the owner-Voter before
        // the persister applies it (and since `securityUpdate` is the only
        // expression on the resource, a gate that consulted any other operation arm
        // would leave this ungated and let the mutation through).
        $response = $this->handle('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks', 'DELETE', [
            'data' => [['type' => 'tracks', 'id' => '1']],
        ], self::NON_OWNER);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->assertJsonApiError($response, '403', 'Forbidden');

        // The denial aborted before the persister: track 1 is still a member.
        self::assertContains('1', $this->trackMembers(), 'the denied mutation must not have removed track 1');
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function anOwnerMayMutateTheirPlaylistTracksRelationship(): void
    {
        // The owner passes the EDIT gate, so the mutation applies — and `tracks` is
        // the INVERSE (`mappedBy`) side of the Track↔Playlist many-to-many, so the
        // reference persister must drop / create the JOIN ROW via the owning side
        // (`Track::$playlists`). This round-trip is the regression witness for that
        // path, which previously `500`ed by assigning a single object to the owning
        // `Collection` property.
        $remove = $this->handle('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks', 'DELETE', [
            'data' => [['type' => 'tracks', 'id' => '1']],
        ], self::OWNER);
        self::assertContains($remove->getStatusCode(), [200, 204], (string) $remove->getContent());
        self::assertNotContains('1', $this->trackMembers(), 'removing the inverse-side member must drop the join row');

        $add = $this->handle('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks', 'POST', [
            'data' => [['type' => 'tracks', 'id' => '1']],
        ], self::OWNER);
        self::assertContains($add->getStatusCode(), [200, 204], (string) $add->getContent());
        self::assertContains('1', $this->trackMembers(), 'adding the inverse-side member must recreate the join row');
    }

    /**
     * The current track-id members of the owned playlist's `tracks` relationship.
     *
     * @return list<mixed>
     */
    private function trackMembers(): array
    {
        $document = $this->decode($this->handle('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks'));
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $member): mixed => \is_array($member) ? ($member['id'] ?? null) : null, $data));
    }

    // --- securityDelete: is_granted('ROLE_ADMIN') (a role gate) ----------------

    #[Test]
    public function anAdminMayDeleteAPlaylist(): void
    {
        $id = $this->createEmptyPlaylist();

        self::assertSame(204, $this->handle('/playlists/' . $id, 'DELETE', null, self::ADMIN)->getStatusCode());
        self::assertSame(404, $this->handle('/playlists/' . $id)->getStatusCode());
    }

    #[Test]
    public function aNonAdminIsForbiddenToDeleteAndThePlaylistSurvives(): void
    {
        $id = $this->createEmptyPlaylist();

        // ada is the seeded owner but only ROLE_USER, so the ROLE_ADMIN delete gate
        // forbids her — ownership does not imply deletion rights here.
        $response = $this->handle('/playlists/' . $id, 'DELETE', null, self::OWNER);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->assertJsonApiError($response, '403', 'Forbidden');

        // The delete never ran: the playlist is still readable.
        self::assertSame(200, $this->handle('/playlists/' . $id)->getStatusCode());
    }

    // --- a type with no security is ungated -----------------------------------

    #[Test]
    public function anUnsecuredResourceIsUngatedEvenUnauthenticated(): void
    {
        // `tracks` declares no `security`, so its operations are never gated by this
        // layer — an unauthenticated update succeeds.
        $response = $this->handle('/tracks/1', 'PATCH', [
            'data' => ['type' => 'tracks', 'id' => '1', 'attributes' => ['title' => 'Airbag (Edit)']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Creates a fresh, empty (track-less) playlist and returns its minted UUID. Create
     * carries no security expression, so anyone may create.
     */
    private function createEmptyPlaylist(): string
    {
        $created = $this->decode($this->handle('/playlists', 'POST', [
            'data' => ['type' => 'playlists', 'attributes' => ['title' => 'Scratch', 'public' => true]],
        ]))['data'] ?? null;
        self::assertIsArray($created);

        $id = $created['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function titleOf(Response $response): string
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        $title = $attributes['title'] ?? null;
        self::assertIsString($title);

        return $title;
    }

    private function assertJsonApiError(Response $response, string $status, string $title): void
    {
        self::assertStringStartsWith('application/vnd.api+json', (string) $response->headers->get('Content-Type'));

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame($status, $first['status'] ?? null);
        self::assertSame($title, $first['title'] ?? null);
    }
}
