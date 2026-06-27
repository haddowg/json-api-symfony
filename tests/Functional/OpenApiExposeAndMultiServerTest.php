<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiExposeGateTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiMultiServerTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The expose-gate (D9) and multi-server (D5) document witnesses: a non-exposed kernel
 * registers no docs route; a per-server kernel serves a per-server document at
 * `/docs.json` and `/admin/docs.json`; the combined mode serves one document at the
 * json path only.
 *
 * Each test boots the specific kernel it needs (rather than one shared kernel), so it
 * snapshots/restores the global error/exception-handler stack itself (booting a kernel
 * installs Symfony's handlers, which PHPUnit's strict mode flags if not restored).
 */
final class OpenApiExposeAndMultiServerTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHandlers();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function noDocumentRouteIsEmittedWhenNotDebugAndNotExposed(): void
    {
        $kernel = new OpenApiExposeGateTestKernel('test', false);
        $kernel->boot();

        $router = $kernel->getContainer()->get('router');
        \assert($router instanceof RouterInterface);
        $paths = \array_map(static fn($r) => $r->getPath(), $router->getRouteCollection()->all());

        // Neither the document, the viewer, nor the JSON Schema route is emitted: all
        // ride the same expose gate as the document (D6/D9).
        self::assertNotContains('/docs.json', $paths);
        self::assertNotContains('/docs', $paths);
        self::assertNotContains('/schemas.json', $paths);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentRouteIsExposedInDebugEvenWithoutExposeInProd(): void
    {
        $kernel = new OpenApiExposeGateTestKernel('test_debug', true);
        $kernel->boot();

        $router = $kernel->getContainer()->get('router');
        \assert($router instanceof RouterInterface);
        $paths = \array_map(static fn($r) => $r->getPath(), $router->getRouteCollection()->all());

        // Debug auto-exposes the document, the viewer (ui.enabled default true) and the
        // JSON Schema route (json_schema.enabled default true).
        self::assertContains('/docs.json', $paths);
        self::assertContains('/docs', $paths);
        self::assertContains('/schemas.json', $paths);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function perServerModeServesEachServersDocumentAtItsOwnPath(): void
    {
        $kernel = new OpenApiMultiServerTestKernel('test', false);
        $kernel->boot();

        $default = $this->document($kernel, '/docs.json');
        $admin = $this->document($kernel, '/admin/docs.json');

        // The default document describes the default server's type only.
        $defaultPaths = $this->asArray($default['paths'] ?? null);
        self::assertArrayHasKey('/public-items', $defaultPaths);
        self::assertArrayNotHasKey('/admin-items', $defaultPaths);
        self::assertSame('https://public.test', $this->serverUrl($default));

        // The admin document describes the admin server's type only.
        $adminPaths = $this->asArray($admin['paths'] ?? null);
        self::assertArrayHasKey('/admin-items', $adminPaths);
        self::assertArrayNotHasKey('/public-items', $adminPaths);
        self::assertSame('https://admin.test', $this->serverUrl($admin));

        // The aggregate JSON Schemas serve per server too, each keyed by its server's
        // type only (the schema twin of the per-server document).
        $defaultSchemas = $this->document($kernel, '/schemas.json');
        self::assertArrayHasKey('public-items', $defaultSchemas);
        self::assertArrayNotHasKey('admin-items', $defaultSchemas);

        $adminSchemas = $this->document($kernel, '/admin/schemas.json');
        self::assertArrayHasKey('admin-items', $adminSchemas);
        self::assertArrayNotHasKey('public-items', $adminSchemas);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function combinedModeEmitsOnlyTheJsonPathRoute(): void
    {
        $kernel = new OpenApiMultiServerTestKernel('test', false, true);
        $kernel->boot();

        $router = $kernel->getContainer()->get('router');
        \assert($router instanceof RouterInterface);
        $paths = \array_map(static fn($r) => $r->getPath(), $router->getRouteCollection()->all());

        // The json-path route is present; the per-server {server}/docs.json route is not.
        // The JSON Schema route mirrors it: the schema path is present, the per-server
        // {server}/schemas.json route is not.
        self::assertContains('/docs.json', $paths);
        self::assertNotContains('/{server}/docs.json', $paths);
        self::assertContains('/schemas.json', $paths);
        self::assertNotContains('/{server}/schemas.json', $paths);

        $kernel->shutdown();
    }

    #[Test]
    #[Group('spec:openapi')]
    public function combinedModeServesOneDocumentSpanningEveryServer(): void
    {
        $kernel = new OpenApiMultiServerTestKernel('test', false, true);
        $kernel->boot();

        // The single combined document at the json path describes BOTH servers' types
        // (D5 / §10): the default server's public-items AND the admin server's
        // admin-items, with both servers' base URIs advertised.
        $combined = $this->document($kernel, '/docs.json');

        $paths = $this->asArray($combined['paths'] ?? null);
        self::assertArrayHasKey('/public-items', $paths);
        self::assertArrayHasKey('/admin-items', $paths);

        $servers = $this->asArray($combined['servers'] ?? null);
        $urls = [];
        foreach ($servers as $server) {
            $urls[] = $this->asArray($server)['url'] ?? null;
        }
        self::assertContains('https://public.test', $urls);
        self::assertContains('https://admin.test', $urls);

        // The combined aggregate schemas at the schema path span BOTH servers' types.
        $combinedSchemas = $this->document($kernel, '/schemas.json');
        self::assertArrayHasKey('public-items', $combinedSchemas);
        self::assertArrayHasKey('admin-items', $combinedSchemas);

        $kernel->shutdown();
    }

    /**
     * @return array<string, mixed>
     */
    private function document(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $path): array
    {
        $response = $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode());

        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * The first advertised server's URL from a document.
     *
     * @param array<array-key, mixed> $document
     */
    private function serverUrl(array $document): mixed
    {
        $servers = $this->asArray($document['servers'] ?? null);
        $first = $this->asArray($servers[0] ?? null);

        return $first['url'] ?? null;
    }

    /**
     * Pops every error/exception handler a booted kernel pushed, back to the snapshot
     * taken in setUp, so the global handler stack is balanced for PHPUnit strict mode.
     */
    private function restoreHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }
}
