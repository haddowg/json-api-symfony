<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The include-safeguards acceptance suite (backs `relationships.md` →
 * "Controlling what can be included" and `configuration.md` → `max_include_depth`).
 * It witnesses the three composing safeguards (bundle ADR 0037) over the reference
 * Doctrine provider, each on the example's real relationship graph:
 *
 *  - Capability A — `ArtistResource::albums` (the back-reference of `albums.artist`)
 *    is `cannotBeIncluded()`, so `?include=albums` from an artist is a `400` while
 *    the forward `albums?include=artist` is unaffected;
 *  - Capability B — `json_api.max_include_depth: 2`, so `?include=tracks.album`
 *    (depth 2) is allowed and a depth-3 path is a `400`;
 *  - Capability C — `UserResource::getAllowedIncludePaths()` permits `playlists`
 *    and `playlists.owner` but not `playlists.tracks`, even though `tracks` is
 *    freely includable when `playlists` is the request's own root.
 */
#[Group('spec:fetching-includes')]
final class IncludeSafeguardsTest extends MusicCatalogKernelTestCase
{
    private const string PLAYLIST_ID = '00000000-0000-4000-8000-000000000001';

    // --- Capability A: per-relation cannotBeIncluded() opt-out -----------------

    #[Test]
    public function includingTheCannotBeIncludedBackReferenceIs400(): void
    {
        // `artists.albums` opted out of inclusion to break the artist↔album loop.
        $this->assertError($this->handle('/artists/1?include=albums'), 400, 'INCLUSION_NOT_ALLOWED');
    }

    #[Test]
    public function theForwardRelationStaysIncludable(): void
    {
        // Opting `artists.albums` out does not touch the distinct forward relation
        // `albums.artist` — `?include=artist` still compounds the artist.
        $response = $this->handle('/albums/1?include=artist');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $included = $this->decode($response)['included'] ?? null;
        self::assertIsArray($included);
        self::assertNotEmpty($included);
    }

    // --- Capability B: max include depth (config default overridden to 2) ------

    #[Test]
    public function anIncludeAtTheConfiguredDepthCapSucceeds(): void
    {
        // depth(tracks.album) = 2 == json_api.max_include_depth, so it is allowed.
        $response = $this->handle('/albums/1?include=tracks.album');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function anIncludeDeeperThanTheConfiguredCapIs400(): void
    {
        // depth(tracks.album.tracks) = 3 > the configured cap of 2.
        $this->assertError($this->handle('/albums/1?include=tracks.album.tracks'), 400, 'INCLUSION_DEPTH_EXCEEDED');
    }

    // --- Capability C: root allowed-include-paths whitelist --------------------

    #[Test]
    public function aTracksIncludeIsAllowedFromItsOwnPlaylistRoot(): void
    {
        // The contrast: `tracks` is freely includable when `playlists` is the
        // request's own root — the very path the user whitelist forbids when nested.
        $response = $this->handle('/playlists/' . self::PLAYLIST_ID . '?include=tracks');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function aPathInsideTheUserWhitelistIsAllowed(): void
    {
        // `users` permits `playlists` and `playlists.owner` (depth 2, the cap).
        self::assertSame(200, $this->handle('/admin/users/1?include=playlists')->getStatusCode());
        self::assertSame(200, $this->handle('/admin/users/1?include=playlists.owner')->getStatusCode());
    }

    #[Test]
    public function aNestedPathOutsideTheUserWhitelistIs400(): void
    {
        // `playlists.tracks` is outside the whitelist — forbidden as a nested path
        // from `users` even though `tracks` is includable from `playlists`' own root.
        $this->assertError($this->handle('/admin/users/1?include=playlists.tracks'), 400, 'INCLUSION_NOT_ALLOWED');
    }

    /**
     * Asserts the response is a JSON:API error document with the given HTTP status
     * and a top error carrying `$code`.
     */
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
