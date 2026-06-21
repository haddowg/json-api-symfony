<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The example app's Atomic Operations witness: the example enables the extension
 * (`atomic_operations.enabled: true` in `config/packages/json_api.yaml`), so
 * `POST /operations` is live. This suite runs the exact worked batch from
 * `docs/atomic-operations.md` — two `playlists` creates — and asserts the documented
 * outcome: `200`, the atomic `ext` advertised on the response `Content-Type`, and an
 * `atomic:results` array of the two created playlists each carrying its server-minted
 * UUID. It also asserts the served OpenAPI document now advertises the atomic endpoint.
 *
 * Keeping this as a PERMANENT example-app test pins the doc's worked example to the
 * real app surface, so the documentation and the configuration cannot silently drift
 * (the batch in the docs is run, byte-for-byte, against the running example).
 */
#[Group('spec:atomic')]
final class AtomicOperationsTest extends MusicCatalogKernelTestCase
{
    private const ATOMIC_EXT = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    #[Test]
    public function theWorkedBatchFromTheDocsCreatesBothPlaylistsAtomically(): void
    {
        // The self-contained batch from docs/atomic-operations.md (the "Try it in the
        // example app" admonition), run as-is.
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'playlists', 'attributes' => ['title' => 'Morning Run', 'public' => true]]],
            ['op' => 'add', 'data' => ['type' => 'playlists', 'attributes' => ['title' => 'Late Night Coding', 'public' => false]]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The response always advertises the extension on its Content-Type.
        self::assertStringContainsString('ext="https://jsonapi.org/ext/atomic"', (string) $response->headers->get('Content-Type'));

        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        self::assertCount(2, $results);

        // One result per operation, in batch order — each the created playlist with a
        // server-minted UUID and its written attributes.
        $first = $this->resourceOf($results, 0);
        self::assertSame('playlists', $first['type'] ?? null);
        self::assertIsString($first['id'] ?? null);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $first['id'],
        );
        $firstAttributes = $first['attributes'] ?? null;
        self::assertIsArray($firstAttributes);
        self::assertSame('Morning Run', $firstAttributes['title'] ?? null);
        self::assertTrue($firstAttributes['public'] ?? null);

        $second = $this->resourceOf($results, 1);
        $secondAttributes = $second['attributes'] ?? null;
        self::assertIsArray($secondAttributes);
        self::assertSame('Late Night Coding', $secondAttributes['title'] ?? null);
        self::assertFalse($secondAttributes['public'] ?? null);

        // The two created playlists are durably persisted and individually readable.
        self::assertSame(200, $this->handle('/playlists/' . $first['id'])->getStatusCode());

        $secondId = $second['id'] ?? null;
        self::assertIsString($secondId);
        self::assertSame(200, $this->handle('/playlists/' . $secondId)->getStatusCode());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theServedOpenApiDocumentAdvertisesTheAtomicEndpoint(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertArrayHasKey('/operations', $paths);

        $operations = $paths['/operations'];
        self::assertIsArray($operations);
        $post = $operations['post'] ?? null;
        self::assertIsArray($post);

        // Grouped under the Atomic Operations tag, defined at the document root.
        self::assertIsArray($post['tags'] ?? null);
        self::assertContains('Atomic Operations', $post['tags']);
        $tagNames = \array_column(\is_array($document['tags'] ?? null) ? $document['tags'] : [], 'name');
        self::assertContains('Atomic Operations', $tagNames);
    }

    /**
     * Issues a `POST /operations` atomic batch, carrying the atomic `ext` media-type
     * parameter on both `Content-Type` and `Accept` exactly as the extension requires
     * (mirroring the bundle's atomic conformance harness).
     *
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $server = [
            'CONTENT_TYPE' => self::ATOMIC_EXT,
            'HTTP_ACCEPT' => self::ATOMIC_EXT,
        ];
        $content = \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR);

        $request = Request::create('/operations', 'POST', server: $server, content: $content);
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        // Rebalance the global error/exception-handler stack the kernel pushed (PHPUnit
        // strict mode flags any imbalance), the same way the base handle() does.
        $this->restoreHandlers();

        return $response;
    }

    /**
     * The `data` resource object of the result at `$index`.
     *
     * @param array<array-key, mixed> $results
     *
     * @return array<string, mixed>
     */
    private function resourceOf(array $results, int $index): array
    {
        $result = $results[$index] ?? null;
        self::assertIsArray($result);
        $data = $result['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
