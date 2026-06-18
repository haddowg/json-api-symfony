<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The custom / non-CRUD actions acceptance suite (G13, bundle ADR 0076): the example
 * app's three witness actions hanging off the `albums` type under the reserved
 * `-actions` segment, exercised end to end over the reference Doctrine data layer.
 *
 *  - `POST /albums/{id}/-actions/reissue` — a resource-scope `Document` action gated by
 *    `is_granted('ROLE_ADMIN')`: a `403` for a non-admin, then an admin reissues the
 *    album under a new `releasedAt`, persisted and rendered back.
 *  - `POST /albums/{id}/-actions/artwork` — a Raw-input binary upload returning `204`,
 *    its bytes attached to the album (observed through a follow-up fetch).
 *  - `POST /albums/-actions/summary` — a collection-scope action returning a meta-only
 *    catalogue summary.
 *
 * It also asserts route ordering: the action paths do not shadow the normal
 * `/albums/{id}` fetch (and vice versa), and an unknown action under `-actions` is a
 * `404`.
 */
#[Group('spec:actions')]
final class CustomActionTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aResourceScopeDocumentActionReissuesAnAlbumForAnAdmin(): void
    {
        // The reissue document is validated against the mount `albums` type before the
        // security gate runs (design §5), so it carries the required `title` alongside
        // the new `releasedAt`.
        $body = [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'OK Computer',
                    'releasedAt' => '2001-10-01T00:00:00+00:00',
                ],
            ],
        ];

        // A non-admin is denied at the per-action security gate.
        $denied = $this->action('/albums/1/-actions/reissue', body: $body, user: 'ada@example.com');
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getContent());

        // An admin reissues album 1 (OK Computer) under a new release date.
        $response = $this->action('/albums/1/-actions/reissue', body: $body, user: 'admin');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame('albums', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        // The new release date is persisted: a follow-up read reflects it.
        $fetched = $this->attributesOf($this->action('/albums/1', 'GET', user: 'admin'));
        $releasedAt = $fetched['releasedAt'] ?? null;
        self::assertIsString($releasedAt);
        self::assertStringStartsWith('2001-10-01', $releasedAt);
    }

    #[Test]
    public function aRawInputActionUploadsArtworkAndReturns204(): void
    {
        $response = $this->handleRaw(
            '/albums/2/-actions/artwork',
            'COVER-ART-BINARY',
            extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin'],
        );

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', (string) $response->getContent());

        // The uploaded bytes are attached to album 2.
        $fetched = $this->attributesOf($this->action('/albums/2', 'GET', user: 'admin'));
        self::assertSame('COVER-ART-BINARY', $fetched['artwork'] ?? null);
    }

    #[Test]
    public function aCollectionScopeActionReturnsACatalogueSummary(): void
    {
        $response = $this->action('/albums/-actions/summary', user: 'admin');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        $albums = $meta['albums'] ?? null;
        self::assertIsArray($albums);
        // The seed is three albums: two published (1, 2), one unpublished (3).
        self::assertSame(2, $albums['published'] ?? null);
        self::assertSame(1, $albums['unpublished'] ?? null);
        self::assertSame(3, $albums['total'] ?? null);
    }

    #[Test]
    public function anActionPathDoesNotShadowANormalFetch(): void
    {
        // Route ordering: the resource-scope action path (4 segments) and a normal
        // /albums/{id} fetch (2 segments) both resolve correctly side by side — the
        // -actions segment is never captured as an {id} or a {relationship}.
        $artwork = $this->handleRaw('/albums/2/-actions/artwork', 'X', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin']);
        self::assertSame(204, $artwork->getStatusCode(), (string) $artwork->getContent());

        // The collection-scope action (3 segments) likewise coexists with the resource
        // route — -actions never lands as an {id}. A non-action method on that path
        // (e.g. GET, when `summary` is POST-only) is NOT silently swallowed by the
        // generic related route `GET /albums/{id}/{relationship}` matching {id} =
        // `-actions`; the {id} requirement excludes the reserved segment so the router
        // 405s. That regression is asserted on both providers by the bundle's
        // CustomActionConformanceTestCase (where the logger is silenced); the framework
        // logs a routing 405 to stderr, which trips this example suite's
        // beStrictAboutOutputDuringTests, so it is not re-asserted here.
        self::assertSame(200, $this->action('/albums/-actions/summary', user: 'admin')->getStatusCode());

        $fetch = $this->action('/albums/2', 'GET', user: 'admin');
        self::assertSame(200, $fetch->getStatusCode(), (string) $fetch->getContent());
        self::assertSame('2', $this->dataOf($fetch)['id'] ?? null);
    }

    /**
     * Issues an authenticated action request (Bearer token = the user identifier).
     *
     * @param array<string, mixed>|null $body
     */
    private function action(string $path, string $method = 'POST', ?array $body = null, string $user = 'admin'): Response
    {
        return $this->handle($path, $method, $body, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user]);
    }

    /**
     * The decoded document's primary `data` object.
     *
     * @return array<string, mixed>
     */
    private function dataOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The decoded document's `data.attributes`.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $attributes = $this->dataOf($response)['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
