<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApiBundle\Examples\MusicCatalog\Hook\AuditLog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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
 * The audit store is read back through the public {@see AuditLog} service.
 */
#[Group('spec:crud')]
final class LifecycleHooksTest extends MusicCatalogKernelTestCase
{
    private const string SEEDED_PLAYLIST_ID = '00000000-0000-4000-8000-000000000001';

    /** HTTP-Basic credentials for the admin user the security firewall grants delete. */
    private const array ADMIN = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'pass'];

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $this->audit()->clear();
    }

    #[Test]
    public function aBeforeCreateHookMutationIsPersisted(): void
    {
        // PlaylistResource::beforeCreate stamps externalId when the create omits it.
        $response = $this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'attributes' => ['title' => 'Focus', 'public' => false],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        // The before-create mutation is on the rendered 201 and persisted: the stamp
        // derives from the minted id, so a follow-up read returns it.
        self::assertSame('ext-' . $id, $attributes['externalId'] ?? null);

        $fetched = $this->decode($this->handle('/playlists/' . $id))['data'] ?? null;
        self::assertIsArray($fetched);
        $fetchedAttributes = $fetched['attributes'] ?? null;
        self::assertIsArray($fetchedAttributes);
        self::assertSame('ext-' . $id, $fetchedAttributes['externalId'] ?? null);
    }

    #[Test]
    public function aBeforeDeleteGuardAbortsWith409AndNothingIsDeleted(): void
    {
        // The seeded playlist still references two tracks, so the beforeDelete guard
        // refuses with a 409 — and the audit subscriber never records a deletion.
        // (Delete is admin-gated by securityDelete, so authenticate as admin to reach
        // the hook — the authorization layer is exercised in AuthorizationTest.)
        $response = $this->handle('/playlists/' . self::SEEDED_PLAYLIST_ID, 'DELETE', null, self::ADMIN);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('CONFLICT', $this->firstErrorCode($response));

        // The guard aborted before the delete: the playlist is still there.
        self::assertSame(200, $this->handle('/playlists/' . self::SEEDED_PLAYLIST_ID)->getStatusCode());
        self::assertSame([], $this->audit()->entries());
    }

    #[Test]
    public function anEmptyPlaylistPassesTheGuardAndDeletes(): void
    {
        // A freshly created playlist references no tracks, so the same guard lets the
        // delete through — proving the guard is conditional, not a blanket block.
        $created = $this->decode($this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'attributes' => ['title' => 'Disposable', 'public' => true],
            ],
        ]))['data'] ?? null;
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        // Delete is admin-gated, so authenticate as admin to reach the guard.
        self::assertSame(204, $this->handle('/playlists/' . $id, 'DELETE', null, self::ADMIN)->getStatusCode());
        self::assertSame(404, $this->handle('/playlists/' . $id)->getStatusCode());
    }

    #[Test]
    public function aSaveSubscriberAuditsEveryCommittedWrite(): void
    {
        // The AfterSave event fires for both create and update; the AfterDelete event
        // for a delete. Each appends one audit line — proving a cross-cutting concern
        // spans the whole API from a single subscriber.
        $created = $this->decode($this->handle('/playlists', 'POST', [
            'data' => [
                'type' => 'playlists',
                'attributes' => ['title' => 'Audited', 'public' => true],
            ],
        ]))['data'] ?? null;
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        $this->handle('/tracks/1', 'PATCH', [
            'data' => ['type' => 'tracks', 'id' => '1', 'attributes' => ['title' => 'Airbag (Remaster)']],
        ]);

        $this->handle('/tracks/4', 'DELETE');

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
        $response = $this->readOnlyRequest(
            '/playlists',
            'POST',
            ['data' => ['type' => 'playlists', 'attributes' => ['title' => 'Blocked', 'public' => true]]],
        );

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('FORBIDDEN', $this->firstErrorCode($response));
        self::assertSame([], $this->audit()->entries());

        // A read is unaffected by the gate (it only blocks mutating methods).
        self::assertSame(200, $this->readOnlyRequest('/playlists/' . self::SEEDED_PLAYLIST_ID, 'GET')->getStatusCode());
    }

    /**
     * Issues a request carrying the `X-Read-Only` header the serving gate watches —
     * the one affordance the base `handle()` does not cover (it sends no custom
     * headers). Mirrors the base flow: production catch + handler-stack restore.
     *
     * @param array<string, mixed>|null $body
     */
    private function readOnlyRequest(string $path, string $method, ?array $body = null): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $server = ['HTTP_ACCEPT' => 'application/vnd.api+json', 'HTTP_X_READ_ONLY' => 'on'];
        $content = null;
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/vnd.api+json';
            $content = \json_encode($body, \JSON_THROW_ON_ERROR);
        }

        $request = Request::create($path, $method, server: $server, content: $content);

        return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }

    /**
     * The `code` of the first error in an error document — the marker a hook abort
     * carries ({@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Hook\HookAbortException}).
     */
    private function firstErrorCode(Response $response): string
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        $code = $first['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    private function audit(): AuditLog
    {
        $audit = static::getContainer()->get(AuditLog::class);
        \assert($audit instanceof AuditLog);

        return $audit;
    }
}
