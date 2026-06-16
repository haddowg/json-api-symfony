<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\MultiServerTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The multi-server witness (bundle ADR 0034): a `default` server (top-level
 * base_uri) and a named `admin` server (its own base_uri), with three resources —
 * one default-only, one admin-only, one on both — and the route loader imported once
 * per server (the admin import under `/admin`). Asserts each resource mounts only on
 * its server(s) (against the route collection, so an unrouted path is not issued as a
 * logged 404), the route names are namespaced per server, and each route resolves its
 * own Server (the rendered convention links carry that server's base_uri).
 */
final class MultiServerTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return MultiServerTestKernel::class;
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theDefaultResourceMountsUnprefixedAndNotUnderTheAdminPrefix(): void
    {
        $paths = $this->routePaths();

        // Reachable at the root; never under /admin (the default-only resource is in
        // the default server's bucket alone).
        self::assertContains('/public-widgets/{id}', $paths);
        self::assertNotContains('/admin/public-widgets/{id}', $paths);

        // The default server keeps the existing unprefixed route names.
        self::assertArrayHasKey('jsonapi.public-widgets.show', $this->routesByName());
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theAdminResourceMountsUnderTheAdminPrefixAndNotAtTheRoot(): void
    {
        $paths = $this->routePaths();

        // Reachable under /admin; never at the root (admin-only).
        self::assertContains('/admin/admin-widgets/{id}', $paths);
        self::assertNotContains('/admin-widgets/{id}', $paths);

        // A named server namespaces its route names so a type on two servers never
        // collides.
        self::assertArrayHasKey('jsonapi.admin.admin-widgets.show', $this->routesByName());
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theBothServersResourceMountsOnBothServersUnderDistinctNames(): void
    {
        $paths = $this->routePaths();
        $names = $this->routesByName();

        self::assertContains('/shared-widgets/{id}', $paths);
        self::assertContains('/admin/shared-widgets/{id}', $paths);

        // The same type, distinct route names per server (no collision).
        self::assertArrayHasKey('jsonapi.shared-widgets.show', $names);
        self::assertArrayHasKey('jsonapi.admin.shared-widgets.show', $names);
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theDefaultResourceResolvesTheDefaultServer(): void
    {
        $response = $this->handle('/public-widgets/p1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public-widgets', $this->primaryType($response));

        self::assertSame(
            MultiServerTestKernel::DEFAULT_BASE_URI . '/public-widgets/p1/relationships/related',
            $this->relationshipSelf($response, 'related'),
        );
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theAdminResourceResolvesTheAdminServer(): void
    {
        $response = $this->handle('/admin/admin-widgets/a1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin-widgets', $this->primaryType($response));

        self::assertSame(
            MultiServerTestKernel::ADMIN_BASE_URI . '/admin-widgets/a1/relationships/related',
            $this->relationshipSelf($response, 'related'),
        );
    }

    #[Test]
    #[Group('spec:multi-server')]
    public function theBothServersResourceResolvesEachServerByRoute(): void
    {
        $fromDefault = $this->handle('/shared-widgets/s1');
        self::assertSame(200, $fromDefault->getStatusCode());
        self::assertSame('shared-widgets', $this->primaryType($fromDefault));

        $fromAdmin = $this->handle('/admin/shared-widgets/s1');
        self::assertSame(200, $fromAdmin->getStatusCode());
        self::assertSame('shared-widgets', $this->primaryType($fromAdmin));

        // The same type, but each route resolves its own Server: the default route's
        // links carry the default base_uri, the admin route's the admin base_uri.
        self::assertSame(
            MultiServerTestKernel::DEFAULT_BASE_URI . '/shared-widgets/s1/relationships/related',
            $this->relationshipSelf($fromDefault, 'related'),
        );
        self::assertSame(
            MultiServerTestKernel::ADMIN_BASE_URI . '/shared-widgets/s1/relationships/related',
            $this->relationshipSelf($fromAdmin, 'related'),
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
