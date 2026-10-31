<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth scopes on the catalogue's writes (core ADR 0181). A token carries its
 * scopes after a `#` (`Bearer admin#catalog:write`); a token without one is unscoped and
 * its requests are not checked for scopes, which is why the other suites are unaffected.
 *
 * The declarations are the ones the Laravel example mirrors byte for byte: album writes
 * need `catalog:write`, playlist writes `library:write`, a playlist delete
 * `library:admin` too, the artwork upload `catalog:media` too, and a batch `batch`.
 */
#[Group('scopes')]
final class ScopesTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aScopedTokenWithoutTheWriteScopeIsRefused(): void
    {
        $response = $this->createAlbum('Bearer ' . SeedManifest::ADMIN . '#library:write');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('Bearer error="insufficient_scope", scope="catalog:write"', $response->headers->get('WWW-Authenticate'));
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('INSUFFICIENT_SCOPE', $error['code'] ?? null);
        self::assertSame(['requiredScopes' => ['catalog:write'], 'missingScopes' => ['catalog:write']], $error['meta'] ?? null);
    }

    #[Test]
    public function aScopedTokenWithTheWriteScopeIsServed(): void
    {
        self::assertSame(Response::HTTP_CREATED, $this->createAlbum('Bearer ' . SeedManifest::ADMIN . '#catalog:write')->getStatusCode());
    }

    #[Test]
    public function anUnscopedTokenIsNotCheckedForScopes(): void
    {
        self::assertSame(Response::HTTP_CREATED, $this->createAlbum('Bearer ' . SeedManifest::ADMIN)->getStatusCode());
    }

    #[Test]
    public function aPlaylistDeleteNeedsTheAdminScopeToo(): void
    {
        $response = $this->handle('/playlists/' . SeedManifest::OWNED_PLAYLIST_ID, 'DELETE', extraServer: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . SeedManifest::ADMIN . '#library:write',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('Bearer error="insufficient_scope", scope="library:write library:admin"', $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    #[Group('openapi')]
    public function theDocumentListsEachWritesScopesUnderTheOAuth2Scheme(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        self::assertSame([['oauth2' => ['catalog:write']]], $this->nested($document, 'paths', '/albums', 'post')['security'] ?? null);
        self::assertSame([['oauth2' => ['library:write', 'library:admin']]], $this->nested($document, 'paths', '/playlists/{id}', 'delete')['security'] ?? null);
        self::assertSame([['oauth2' => ['catalog:write', 'catalog:media']]], $this->nested($document, 'paths', '/albums/{id}/-actions/artwork', 'post')['security'] ?? null);
        self::assertSame([['oauth2' => ['batch']]], $this->nested($document, 'paths', '/operations', 'post')['security'] ?? null);
        self::assertArrayNotHasKey('security', $this->nested($document, 'paths', '/albums/-actions/summary', 'post'));

        $flow = $this->nested($document, 'components', 'securitySchemes', 'oauth2', 'flows', 'clientCredentials');
        self::assertSame(['catalog:write', 'catalog:media', 'library:write', 'library:admin', 'batch'], \array_keys($this->nested($flow, 'scopes')));
    }

    #[Test]
    #[Group('openapi')]
    public function theStrictExportReportsNoUndeclaredScope(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        foreach (['default', 'admin'] as $server) {
            $tester = new CommandTester((new Application($kernel))->find('json-api:openapi:export'));
            $tester->execute([
                '--server' => $server,
                '--strict' => true,
                '--output' => \sys_get_temp_dir() . '/json-api-symfony-examples/scopes-' . $server . '.json',
            ], ['capture_stderr_separately' => true]);

            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput());
            self::assertStringNotContainsString('scope', $tester->getErrorOutput());
        }
    }

    private function createAlbum(string $authorization): Response
    {
        return $this->handle('/albums', 'POST', ['data' => ['type' => 'albums', 'attributes' => ['title' => 'Scoped']]], [
            'HTTP_AUTHORIZATION' => $authorization,
        ]);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function nested(array $data, string ...$keys): array
    {
        foreach ($keys as $key) {
            $data = $data[$key] ?? null;
            self::assertIsArray($data, $key);
        }

        return $data;
    }
}
