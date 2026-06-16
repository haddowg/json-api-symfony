<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\StandaloneRelationsTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The standalone-relations witness (ADR 0026): the resource-less `posts` type
 * declares its relations via `#[AsJsonApiRelations]` on a
 * {@see \haddowg\JsonApiBundle\Server\RelationsProviderInterface} class, with no
 * {@see \haddowg\JsonApi\Resource\AbstractResource}. Its serializer renders those
 * relations via core's `RendersRelationsTrait`, and the route loader emits the
 * relationship endpoints because the type has relations — proving a resource-less
 * type gets working relationship rendering + endpoints. Storage-orthogonal, so
 * witnessed on the in-memory kernel only.
 */
final class StandaloneRelationsTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return StandaloneRelationsTestKernel::class;
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function aResourcelessTypeRendersItsStandaloneRelationships(): void
    {
        $response = $this->handle('/posts/p1');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('posts', $data['type'] ?? null);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);

        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertSame(['type' => 'authors', 'id' => '1'], $author['data'] ?? null);

        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        $commentData = $comments['data'] ?? null;
        self::assertIsArray($commentData);
        self::assertContains(['type' => 'comments', 'id' => '1'], $commentData);
        self::assertContains(['type' => 'comments', 'id' => '2'], $commentData);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedEndpointResolvesOffAResourcelessParent(): void
    {
        $response = $this->handle('/posts/p1/author');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('authors', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Ada', $attributes['name'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipLinkageEndpointResolvesOffAResourcelessParent(): void
    {
        $response = $this->handle('/posts/p1/relationships/author');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertSame(['type' => 'authors', 'id' => '1'], $data);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function aResourcelessTypeWithRelationsEmitsRelationshipRoutesButOnlyItsDeclaredPrimaryRoute(): void
    {
        $routesByMethod = $this->routePathsByMethod();
        $paths = $this->routeCollectionPaths();

        // The relationship routes are emitted for the resource-less type.
        self::assertContains('/posts/{id}/relationships/{relationship}', $paths);
        self::assertContains('/posts/{id}/{relationship}', $paths);

        // Only FetchOne is exposed for the primary op: GET /posts/{id}, nothing else.
        self::assertContains('GET /posts/{id}', $routesByMethod);
        self::assertNotContains('GET /posts', $routesByMethod);
        self::assertNotContains('POST /posts', $routesByMethod);
        self::assertNotContains('PATCH /posts/{id}', $routesByMethod);
        self::assertNotContains('DELETE /posts/{id}', $routesByMethod);
    }

    /**
     * The booted router's route collection paths as `"<METHOD> <path>"` strings.
     *
     * @return list<string>
     */
    private function routePathsByMethod(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection() as $route) {
            $methods = $route->getMethods();
            if ($methods === []) {
                $methods = ['ANY'];
            }
            foreach ($methods as $method) {
                $routes[] = $method . ' ' . $route->getPath();
            }
        }

        return $routes;
    }

    /**
     * The booted router's route collection paths (ignoring method).
     *
     * @return list<string>
     */
    private function routeCollectionPaths(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $paths = [];
        foreach ($router->getRouteCollection() as $route) {
            $paths[] = $route->getPath();
        }

        return $paths;
    }
}
