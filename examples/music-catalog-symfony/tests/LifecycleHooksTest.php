<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Hook\AuditLog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The lifecycle-hooks acceptance suite (backs `lifecycle-hooks.md`): the
 * per-operation hook seam end to end over both mechanisms the bundle ships.
 *
 *  - the **resource-method** path on
 *    {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\PlaylistResource}:
 *    a `beforeCreate` that mutates the entity (and the mutation persists) and a
 *    `beforeDelete` guard that aborts with a `409`;
 *  - the **cross-cutting event-subscriber** path on
 *    {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\EventListener\AuditLogSubscriber}:
 *    an audit record appended on every committed write, and a `serving` gate that
 *    freezes writes when a header is set.
 *
 * The audit store is read back through the public {@see AuditLog} service. Delete is
 * admin-gated by `securityDelete`, so the delete witnesses authenticate as `admin`
 * through the shipped {@see \haddowg\JsonApiBundle\Testing\JsonApiBrowser::actingAs()}
 * stateless Bearer token — the same auth path a consumer would use.
 */
#[Group('spec:crud')]
final class LifecycleHooksTest extends MusicCatalogKernelTestCase
{
    private const string SEEDED_PLAYLIST_ID = SeedManifest::OWNED_PLAYLIST_ID;

    /** The admin user the security firewall grants delete. */
    private const string ADMIN = SeedManifest::ADMIN;

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $this->audit()->clear();
    }

    #[Test]
    public function aBeforeCreateHookMutationIsPersisted(): void
    {
        // PlaylistResource::beforeCreate stamps externalId when the create omits it.
        $browser = $this->browser();
        $browser->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => ['title' => 'Focus', 'public' => false],
            ],
        ])->assertCreated();

        $data = $this->decode($browser->getResponse())['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        // The before-create mutation is on the rendered 201: the stamp derives from
        // the minted id.
        $browser->getDocument()->assertHasAttribute('externalId', 'ext-' . $id);

        // …and it persisted: a follow-up read returns the same stamp.
        $this->browser()->get('/playlists/' . $id)->assertFetchedOne()->assertHasAttribute('externalId', 'ext-' . $id);
    }

    #[Test]
    public function aBeforeDeleteGuardAbortsWith409AndNothingIsDeleted(): void
    {
        // The seeded playlist still references two tracks, so the beforeDelete guard
        // refuses with a 409 — and the audit subscriber never records a deletion.
        // (Delete is admin-gated by securityDelete, so authenticate as admin to reach
        // the hook — the authorization layer is exercised in AuthorizationTest.)
        $errors = $this->browser()
            ->actingAs(self::ADMIN)
            ->delete('/playlists/' . self::SEEDED_PLAYLIST_ID)
            ->getErrors();
        $errors->assertStatus(409)->assertHasError(code: 'CONFLICT');

        // The guard aborted before the delete: the playlist is still there.
        $this->browser()->get('/playlists/' . self::SEEDED_PLAYLIST_ID)->assertFetchedOne();
        self::assertSame([], $this->audit()->entries());
    }

    #[Test]
    public function anEmptyPlaylistPassesTheGuardAndDeletes(): void
    {
        // A freshly created playlist references no tracks, so the same guard lets the
        // delete through — proving the guard is conditional, not a blanket block.
        $this->browser()->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => ['title' => 'Disposable', 'public' => true],
            ],
        ])->assertCreated();

        $created = $this->decode($this->browser()->getResponse())['data'] ?? null;
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        // Delete is admin-gated, so authenticate as admin to reach the guard.
        $this->browser()->actingAs(self::ADMIN)->delete('/playlists/' . $id)->assertNoContent();
        $this->browser()->get('/playlists/' . $id)->getDocument()->assertStatus(404);
    }

    #[Test]
    public function aSaveSubscriberAuditsEveryCommittedWrite(): void
    {
        // The AfterSave event fires for both create and update; the AfterDelete event
        // for a delete. Each appends one audit line — proving a cross-cutting concern
        // spans the whole API from a single subscriber.
        $this->browser()->post('/playlists', [
            'data' => [
                'type' => 'playlists',
                'attributes' => ['title' => 'Audited', 'public' => true],
            ],
        ])->assertCreated();

        $created = $this->decode($this->browser()->getResponse())['data'] ?? null;
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        $this->browser()->patch('/tracks/1', [
            'data' => ['type' => 'tracks', 'id' => '1', 'attributes' => ['title' => 'Airbag (Remaster)']],
        ])->assertFetchedOne();

        $this->browser()->delete('/tracks/4')->assertNoContent();

        self::assertSame(
            [
                'created playlists#' . $id,
                'updated tracks#1',
                'deleted tracks#4',
            ],
            $this->audit()->entries(),
        );
    }

    #[Test]
    public function aServingGateFreezesWritesWhenTheHeaderIsSet(): void
    {
        // The serving subscriber fires once per request before the operation: with the
        // read-only header set a write aborts with a 403 and never commits (no audit).
        $this->browser()
            ->post(
                '/playlists',
                ['data' => ['type' => 'playlists', 'attributes' => ['title' => 'Blocked', 'public' => true]]],
                self::READ_ONLY_HEADER,
            )
            ->getErrors()
            ->assertStatus(403)
            ->assertHasError(code: 'FORBIDDEN');
        self::assertSame([], $this->audit()->entries());

        // A read is unaffected by the gate (it only blocks mutating methods).
        $this->browser()->get('/playlists/' . self::SEEDED_PLAYLIST_ID, self::READ_ONLY_HEADER)->assertFetchedOne();
    }

    /** The `X-Read-Only` header the serving gate watches, as a `$_SERVER` entry. */
    private const array READ_ONLY_HEADER = ['HTTP_X_READ_ONLY' => 'on'];

    private function audit(): AuditLog
    {
        $audit = static::getContainer()->get(AuditLog::class);
        \assert($audit instanceof AuditLog);

        return $audit;
    }
}
