<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The worked-example acceptance suite for **one entity backing two JSON:API
 * resource types** (backs `docs/resources.md`, "One entity, two resource types").
 *
 * The seeded {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User} "Ada"
 * (id 1) is exposed twice:
 *  - as the admin-only `users` type ({@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\UserResource},
 *    `server: 'admin'`) — the FULL record (display name, email, birth date, last-seen IP);
 *  - as the curated `public-profiles` type ({@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\PublicProfileResource},
 *    default server) — only the display name.
 *
 * Both declare `#[AsJsonApiResource(entity: User::class)]`; the type→entity map the
 * bundle builds tolerates two types → one entity, so each route resolves the same
 * row through the same Doctrine provider. The seeded "Morning Mix" playlist (owned
 * by Ada) declares two relations off its single `owner` column — `owner` → `users`
 * and `publicOwner` → `public-profiles` — proving a relation chooses its target type
 * with `->type('…')`.
 */
#[Group('spec:fetching')]
final class MultiTypeEntityTest extends MusicCatalogKernelTestCase
{
    /** Ada's UUID-keyed playlist (owned by ada@example.com). */
    private const string PLAYLIST = '00000000-0000-4000-8000-000000000001';

    #[Test]
    public function theAdminUsersTypeRendersTheFullRecord(): void
    {
        // The admin server exposes `users` — the full view (mounted under /admin).
        $data = $this->fetchResource('/admin/users/1');

        self::assertSame('users', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        // The full record carries the private columns.
        self::assertSame('Ada', $attributes['displayName'] ?? null);
        self::assertSame('ada@example.com', $attributes['email'] ?? null);
        self::assertArrayHasKey('birthDate', $attributes);
        self::assertArrayHasKey('lastSeenIp', $attributes);
    }

    #[Test]
    public function thePublicProfilesTypeRendersTheSameRecordAsACuratedView(): void
    {
        // The default server exposes `public-profiles` — the SAME row (id 1, Ada),
        // a strictly narrower projection.
        $data = $this->fetchResource('/public-profiles/1');

        self::assertSame('public-profiles', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        // The same record's public attribute is present...
        self::assertSame('Ada', $attributes['displayName'] ?? null);
        // ...and every private column the `users` view exposes is ABSENT here: the
        // curation is the field inventory, so no fieldset/include can resurface them.
        self::assertArrayNotHasKey('email', $attributes);
        self::assertArrayNotHasKey('birthDate', $attributes);
        self::assertArrayNotHasKey('lastSeenIp', $attributes);
        self::assertArrayNotHasKey('preferences', $attributes);
        self::assertArrayNotHasKey('password', $attributes);
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theTwoTypesAreServedOnDistinctServers(): void
    {
        // `public-profiles` is on the default server, `users` only on admin: the two
        // views of the one entity never collide. Asserted against the booted route
        // collection rather than by issuing a request to an absent path (an unrouted
        // path logs a NotFoundHttpException PHPUnit flags as risky — the convention
        // MultiServerTest follows).
        $paths = $this->routePaths();

        self::assertContains('/public-profiles/{id}', $paths);
        self::assertNotContains('/admin/public-profiles/{id}', $paths);

        self::assertContains('/admin/users/{id}', $paths);
        self::assertNotContains('/users/{id}', $paths);
    }

    #[Test]
    public function thePublicProfilesTypeIsReadOnly(): void
    {
        // The operation allow-list omits create/update/delete, so the curated view
        // routes only the two GET endpoints — there is no write surface for it (a
        // public profile is mutated through the admin `users` resource).
        $paths = $this->routePaths();

        self::assertContains('/public-profiles', $paths);
        self::assertContains('/public-profiles/{id}', $paths);

        // No POST collection / PATCH / DELETE route is emitted for it. (The methods on
        // the two GET routes are GET only.)
        foreach ($this->router()->getRouteCollection() as $route) {
            if (\in_array($route->getPath(), ['/public-profiles', '/public-profiles/{id}'], true)) {
                self::assertSame(['GET'], $route->getMethods(), $route->getPath() . ' is GET-only');
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipTargetingTheCuratedTypeRendersThatTypesLinkage(): void
    {
        // The playlist's `publicOwner` relation declares `->type('public-profiles')`,
        // so its linkage identifies the curated type — even though the same `owner`
        // column also backs the `owner` → `users` relation.
        $relationships = $this->relationshipsOf($this->fetchResource('/playlists/' . self::PLAYLIST));

        $publicOwner = $relationships['publicOwner'] ?? null;
        self::assertIsArray($publicOwner);
        self::assertSame(['type' => 'public-profiles', 'id' => '1'], $publicOwner['data'] ?? null);

        // The sibling `owner` relation off the SAME column declares `users` — an
        // admin-only type. On the DEFAULT server the `users` serializer is not in the
        // registry, so its linkage renders links-only (no `data` member): the public
        // surface dereferences only the public view. The full `users` linkage appears
        // on the admin server (asserted below).
        $owner = $relationships['owner'] ?? null;
        self::assertIsArray($owner);
        self::assertArrayNotHasKey('data', $owner);
        self::assertArrayHasKey('links', $owner);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointRendersTheCuratedTypeLinkage(): void
    {
        // The dedicated relationship (linkage) endpoint resolves the same curated type.
        $document = $this->fetchDocument('/playlists/' . self::PLAYLIST . '/relationships/publicOwner');

        self::assertSame(['type' => 'public-profiles', 'id' => '1'], $document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointRendersTheCuratedResource(): void
    {
        // `GET /playlists/{id}/publicOwner` renders the full related resource AS the
        // curated `public-profiles` type — the public view of the owner.
        $data = $this->fetchResource('/playlists/' . self::PLAYLIST . '/publicOwner');

        self::assertSame('public-profiles', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Ada', $attributes['displayName'] ?? null);
        self::assertArrayNotHasKey('email', $attributes);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function includingTheRelationExpandsTheCuratedResource(): void
    {
        // `?include=publicOwner` compounds the owner AS the curated type in `included`,
        // display name only.
        $document = $this->fetchDocument('/playlists/' . self::PLAYLIST . '?include=publicOwner');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $profile = $this->findIncluded($included, 'public-profiles', '1');
        self::assertNotNull($profile, 'the included document carries the curated public-profiles resource');

        $attributes = $profile['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Ada', $attributes['displayName'] ?? null);
        self::assertArrayNotHasKey('email', $attributes);
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

    private function router(): RouterInterface
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }

    /**
     * @param array<mixed> $included
     *
     * @return array<string, mixed>|null
     */
    private function findIncluded(array $included, string $type, string $id): ?array
    {
        foreach ($included as $resource) {
            if (!\is_array($resource)) {
                continue;
            }
            if (($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                /** @var array<string, mixed> $resource */
                return $resource;
            }
        }

        return null;
    }
}
