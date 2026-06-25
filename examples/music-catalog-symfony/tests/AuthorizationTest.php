<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The declarative-authorization acceptance suite (backs `docs/authorization.md`):
 * the example secures `playlists` with two `#[AsJsonApiResource(security: …)]`
 * expressions, and the bundle's built-in `ResourceSecuritySubscriber` evaluates
 * them at the lifecycle hooks behind the example's stateless Bearer firewall
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
 *
 * Authentication runs through the shipped `JsonApiBrowser::actingAs()` — a stateless
 * Bearer access token the firewall resolves to the seeded user — so the suite
 * dogfoods the same auth path a consumer would use.
 */
#[Group('spec:crud')]
final class AuthorizationTest extends MusicCatalogKernelTestCase
{
    /** The seeded "Morning Mix" playlist (owned by ada@example.com) — a UUID PK. */
    private const string OWNED_PLAYLIST_ID = '00000000-0000-4000-8000-000000000001';

    /** The seeded owner of Morning Mix — the EDIT-gate subject. */
    private const string OWNER = 'ada@example.com';

    /** A ROLE_USER who is not the owner — the Voter refuses her EDIT. */
    private const string NON_OWNER = 'mallory@example.com';

    // --- securityUpdate: is_granted('EDIT', object) (the owner Voter) ----------

    #[Test]
    public function theOwnerMayUpdateTheirPlaylist(): void
    {
        $this->browser()
            ->actingAs(self::OWNER)
            ->patch('/playlists/' . self::OWNED_PLAYLIST_ID, [
                'data' => [
                    'type' => 'playlists',
                    'id' => self::OWNED_PLAYLIST_ID,
                    'attributes' => ['title' => 'Evening Mix'],
                ],
            ])
            ->assertFetchedOne()
            ->assertHasAttribute('title', 'Evening Mix');
    }

    #[Test]
    public function aNonOwnerIsForbiddenToUpdateAndNothingChanges(): void
    {
        $this->browser()
            ->actingAs(self::NON_OWNER)
            ->patch('/playlists/' . self::OWNED_PLAYLIST_ID, [
                'data' => [
                    'type' => 'playlists',
                    'id' => self::OWNED_PLAYLIST_ID,
                    'attributes' => ['title' => 'Hijacked'],
                ],
            ])
            ->getErrors()
            ->assertStatus(403)
            ->assertContentType()
            ->assertHasError(status: '403');

        // The denial aborted before the persister ran: the title is untouched.
        $this->browser()
            ->actingAs(self::OWNER)
            ->get('/playlists/' . self::OWNED_PLAYLIST_ID)
            ->assertFetchedOne()
            ->assertHasAttribute('title', 'Morning Mix');
    }

    #[Test]
    public function anUnauthenticatedUpdateIsUnauthorized(): void
    {
        // No credentials: authentication would unlock the operation, so a denial is a
        // 401, not a 403 (the AuthenticationException the firewall surfaces is mapped).
        $this->browser()
            ->patch('/playlists/' . self::OWNED_PLAYLIST_ID, [
                'data' => [
                    'type' => 'playlists',
                    'id' => self::OWNED_PLAYLIST_ID,
                    'attributes' => ['title' => 'Anon'],
                ],
            ])
            ->getErrors()
            ->assertStatus(401)
            ->assertContentType()
            ->assertHasError(status: '401');
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
        $this->browser()
            ->actingAs(self::NON_OWNER)
            ->delete('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks', [
                'data' => [['type' => 'tracks', 'id' => '1']],
            ])
            ->getErrors()
            ->assertStatus(403)
            ->assertContentType()
            ->assertHasError(status: '403');

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
        $browser = $this->browser()->actingAs(self::OWNER);

        $remove = $browser->delete('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks', [
            'data' => [['type' => 'tracks', 'id' => '1']],
        ])->getResponse();
        self::assertContains($remove->getStatusCode(), [200, 204], (string) $remove->getContent());
        self::assertNotContains('1', $this->trackMembers(), 'removing the inverse-side member must drop the join row');

        $add = $browser->post('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks', [
            'data' => [['type' => 'tracks', 'id' => '1']],
        ])->getResponse();
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
        $document = $this->decode($this->browser()->get('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/tracks')->getResponse());
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $member): mixed => \is_array($member) ? ($member['id'] ?? null) : null, $data));
    }

    // --- per-relation security: owner is admin-only on a public playlist ------

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theOwnerRelationReadIsAdminOnlyEvenThoughThePlaylistIsPublic(): void
    {
        // The playlist itself is publicly readable — anyone may fetch it...
        $this->browser()->get('/playlists/' . self::OWNED_PLAYLIST_ID)->assertFetchedOne();

        // ...but the `owner` relation declares its OWN read gate
        // (`security(read: "is_granted('ROLE_ADMIN')")`), so its relationship-linkage
        // endpoint is admin-only. An unauthenticated caller is 401 (asserted first, while
        // the shared browser still carries no token).
        $this->browser()
            ->get('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/owner')
            ->getErrors()
            ->assertStatus(401)
            ->assertHasError(status: '401');

        // A plain ROLE_USER is forbidden (403).
        $this->browser()
            ->actingAs(self::NON_OWNER)
            ->get('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/owner')
            ->getErrors()
            ->assertStatus(403)
            ->assertContentType()
            ->assertHasError(status: '403');

        // An admin passes the relation's gate (200). (The linkage itself renders
        // data-less here because `owner` targets the admin-server `users` type, off the
        // default surface — a separate multi-server concern; what matters is the gate
        // opened for the admin where it denied everyone else.)
        $this->browser()
            ->actingAs('admin')
            ->get('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/owner')
            ->getDocument()
            ->assertStatus(200);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function thePublicOwnerRelationReadStaysOpen(): void
    {
        // The curated `publicOwner` view of the SAME User declares no security, so it
        // inherits the (ungated) playlist read: anyone may read it, even unauthenticated.
        // This is the contrast — two relations off one resource, gated independently.
        $this->browser()
            ->get('/playlists/' . self::OWNED_PLAYLIST_ID . '/relationships/publicOwner')
            ->getDocument()
            ->assertStatus(200)
            ->assertHasType('public-profiles');
    }

    // --- securityDelete: is_granted('ROLE_ADMIN') (a role gate) ----------------

    #[Test]
    public function anAdminMayDeleteAPlaylist(): void
    {
        $id = $this->createEmptyPlaylist();

        $this->browser()->actingAs('admin')->delete('/playlists/' . $id)->assertNoContent();
        $this->browser()->actingAs('admin')->get('/playlists/' . $id)->getDocument()->assertStatus(404);
    }

    #[Test]
    public function aNonAdminIsForbiddenToDeleteAndThePlaylistSurvives(): void
    {
        $id = $this->createEmptyPlaylist();

        // ada is the seeded owner but only ROLE_USER, so the ROLE_ADMIN delete gate
        // forbids her — ownership does not imply deletion rights here.
        $this->browser()
            ->actingAs(self::OWNER)
            ->delete('/playlists/' . $id)
            ->getErrors()
            ->assertStatus(403)
            ->assertContentType()
            ->assertHasError(status: '403');

        // The delete never ran: the playlist is still readable.
        $this->browser()->actingAs(self::OWNER)->get('/playlists/' . $id)->assertFetchedOne();
    }

    // --- a type with no security is ungated -----------------------------------

    #[Test]
    public function anUnsecuredResourceIsUngatedEvenUnauthenticated(): void
    {
        // `tracks` declares no `security`, so its operations are never gated by this
        // layer — an unauthenticated update succeeds.
        $this->browser()
            ->patch('/tracks/1', [
                'data' => ['type' => 'tracks', 'id' => '1', 'attributes' => ['title' => 'Airbag (Edit)']],
            ])
            ->assertFetchedOne();
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Creates a fresh, empty (track-less) playlist and returns its minted UUID. Create
     * carries no security expression, so anyone may create.
     */
    private function createEmptyPlaylist(): string
    {
        $created = $this->decode($this->browser()->post('/playlists', [
            'data' => ['type' => 'playlists', 'attributes' => ['title' => 'Scratch', 'public' => true]],
        ])->getResponse())['data'] ?? null;
        self::assertIsArray($created);

        $id = $created['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
