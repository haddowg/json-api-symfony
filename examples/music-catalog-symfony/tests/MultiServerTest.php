<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The multi-server witness (bundle ADR 0034) for the example app: the implicit
 * `default` server (top-level `base_uri`, `https://music.example`) plus a named
 * `admin` server (its own `base_uri`, `https://admin.music.example`), declared in
 * `config/packages/json_api.yaml` and mounted by the two per-server imports in
 * `config/routes/json_api.yaml` (the `admin` import under `/admin`).
 *
 * Three resources exercise the three assignment shapes: `albums` is shared
 * (`server: ['default', 'admin']`), `users` is admin-only (`server: 'admin'`), and
 * `artists` is default-only (no `server:` argument → the implicit `default`).
 *
 * Reachability is asserted against the booted route collection rather than by
 * issuing an HTTP request to an absent path — an unrouted path would surface as a
 * framework-logged `NotFoundHttpException`, which PHPUnit's strict mode flags as
 * risky-test noise (the convention `tests/Functional/OperationsTest` and the
 * bundle's own `MultiServerTest` follow). Per-server `base_uri` resolution is then
 * proven on the wire: each route resolves its own `Server`, so the rendered
 * convention links carry that server's `base_uri`.
 */
final class MultiServerTest extends MusicCatalogKernelTestCase
{
    private const string DEFAULT_BASE_URI = 'https://music.example';

    private const string ADMIN_BASE_URI = 'https://admin.music.example';

    #[Test]
    #[Group('spec:multi-server')]
    public function theDefaultOnlyResourceMountsUnprefixedAndNotUnderTheAdminPrefix(): void
    {
        $paths = $this->routePaths();

        // `artists` declares no `server:` argument, so it lands on the implicit
        // `default` server alone: reachable at the root, never under /admin.
        self::assertContains('/artists/{id}', $paths);
        self::assertNotContains('/admin/artists/{id}', $paths);

        // The default server keeps the existing unprefixed route names.
        self::assertArrayHasKey('jsonapi.artists.show', $this->routesByName());
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theAdminOnlyResourceMountsUnderTheAdminPrefixAndNotAtTheRoot(): void
    {
        $paths = $this->routePaths();

        // `users` is `server: 'admin'`: reachable under /admin, never at the root, so
        // `GET /users/{id}` 404s on the default surface while `/admin/users/{id}`
        // resolves.
        self::assertContains('/admin/users/{id}', $paths);
        self::assertNotContains('/users/{id}', $paths);

        // A named server namespaces its route names.
        self::assertArrayHasKey('jsonapi.admin.users.show', $this->routesByName());
        self::assertArrayNotHasKey('jsonapi.users.show', $this->routesByName());
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theSharedResourceMountsOnBothServersUnderDistinctNames(): void
    {
        $paths = $this->routePaths();
        $names = $this->routesByName();

        // `albums` is `server: ['default', 'admin']`: mounted on both surfaces.
        self::assertContains('/albums/{id}', $paths);
        self::assertContains('/admin/albums/{id}', $paths);

        // The same type, distinct route names per server (no collision).
        self::assertArrayHasKey('jsonapi.albums.show', $names);
        self::assertArrayHasKey('jsonapi.admin.albums.show', $names);
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theDefaultOnlyResourceResolvesTheDefaultServer(): void
    {
        $response = $this->handle('/artists/1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('artists', $this->primaryType($response));

        self::assertSame(
            self::DEFAULT_BASE_URI . '/artists/1/relationships/albums',
            $this->relationshipSelf($response, 'albums'),
        );
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theAdminOnlyResourceResolvesTheAdminServer(): void
    {
        $response = $this->handle('/admin/users/1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('users', $this->primaryType($response));

        self::assertSame(
            self::ADMIN_BASE_URI . '/users/1/relationships/playlists',
            $this->relationshipSelf($response, 'playlists'),
        );
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theSharedResourceResolvesEachServerByRoute(): void
    {
        $fromDefault = $this->handle('/albums/1');
        self::assertSame(200, $fromDefault->getStatusCode());
        self::assertSame('albums', $this->primaryType($fromDefault));

        $fromAdmin = $this->handle('/admin/albums/1');
        self::assertSame(200, $fromAdmin->getStatusCode());
        self::assertSame('albums', $this->primaryType($fromAdmin));

        // The same type, but each route resolves its own Server: the default route's
        // links carry the default base_uri, the admin route's the admin base_uri.
        self::assertSame(
            self::DEFAULT_BASE_URI . '/albums/1/relationships/artist',
            $this->relationshipSelf($fromDefault, 'artist'),
        );
        self::assertSame(
            self::ADMIN_BASE_URI . '/albums/1/relationships/artist',
            $this->relationshipSelf($fromAdmin, 'artist'),
        );
    }

    private function primaryType(Response $response): ?string
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $type = $data['type'] ?? null;

        return \is_string($type) ? $type : null;
    }

    private function relationshipSelf(Response $response, string $relationship): ?string
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $relation = $relationships[$relationship] ?? null;
        self::assertIsArray($relation);

        $links = $relation['links'] ?? null;
        self::assertIsArray($links);

        $self = $links['self'] ?? null;

        return \is_string($self) ? $self : null;
    }

    /**
     * The booted router's route paths.
     *
     * @return list<string>
     */
    private function routePaths(): array
    {
        $paths = [];
        foreach ($this->router()->getRouteCollection() as $route) {
            $paths[] = $route->getPath();
        }

        return $paths;
    }

    /**
     * The booted router's routes keyed by name.
     *
     * @return array<string, \Symfony\Component\Routing\Route>
     */
    private function routesByName(): array
    {
        return $this->router()->getRouteCollection()->all();
    }

    private function router(): RouterInterface
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
