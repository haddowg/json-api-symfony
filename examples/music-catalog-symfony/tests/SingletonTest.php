<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The `accounts` singleton at `/me`: the signed-in user's own account (docs/resources.md).
 * The owner is user 1; the admin identity has no account row.
 */
final class SingletonTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function meIsTheSignedInUsersAccount(): void
    {
        $response = $this->as(SeedManifest::OWNER, '/me');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('accounts', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
        self::assertSame(['email' => SeedManifest::OWNER, 'displayName' => 'Ada'], $data['attributes'] ?? null);
        self::assertSame('https://music.example/me', self::at($data, 'links', 'self', 'href'));
        self::assertSame('https://music.example/me/relationships/playlists', self::at($data, 'relationships', 'playlists', 'links', 'self', 'href'));
    }

    #[Test]
    public function aCallerWithNoAccountGetsA404(): void
    {
        self::assertSame(Response::HTTP_NOT_FOUND, $this->handle('/me')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as(SeedManifest::ADMIN, '/me')->getStatusCode());
    }

    #[Test]
    public function anUpdateRenamesTheCallersAccount(): void
    {
        $response = $this->as(SeedManifest::OWNER, '/me', 'PATCH', ['data' => ['type' => 'accounts', 'id' => '1', 'attributes' => ['displayName' => 'Countess']]]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('Countess', self::at($this->decode($this->as(SeedManifest::OWNER, '/me')), 'data', 'attributes', 'displayName'));

        $conflict = $this->as(SeedManifest::OWNER, '/me', 'PATCH', ['data' => ['type' => 'accounts', 'id' => '2', 'attributes' => ['displayName' => 'Someone']]]);
        self::assertSame(Response::HTTP_CONFLICT, $conflict->getStatusCode());
    }

    #[Test]
    public function theAccountsPlaylistsAreItsRelatedResources(): void
    {
        $document = $this->decode($this->as(SeedManifest::OWNER, '/me/playlists'));

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame([SeedManifest::OWNED_PLAYLIST_ID], \array_column($data, 'id'));
    }

    #[Test]
    public function meServesNoCollectionCreateOrDelete(): void
    {
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->as(SeedManifest::OWNER, '/me', 'DELETE')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->as(SeedManifest::OWNER, '/me/1')->getStatusCode());

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach ($router->getRouteCollection() as $route) {
            if ($route->getDefault('_jsonapi_type') === 'accounts') {
                self::assertStringStartsWith('/me', $route->getPath());
                self::assertStringNotContainsString('{id}', $route->getPath());
            }
        }
    }

    #[Test]
    public function theDocumentServesTheAccountAtMe(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer(null)->toArray();

        $paths = \array_keys((array) ($document['paths'] ?? []));
        self::assertContains('/me', $paths);
        self::assertContains('/me/playlists', $paths);
        self::assertContains('/me/relationships/playlists', $paths);
        self::assertNotContains('/me/{id}', $paths);
        self::assertSame('/me', self::at($document, 'components', 'schemas', 'AccountsResource', 'x-singleton'));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function as(string $user, string $path, string $method = 'GET', ?array $body = null): Response
    {
        return $this->handle($path, $method, $body, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user]);
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
