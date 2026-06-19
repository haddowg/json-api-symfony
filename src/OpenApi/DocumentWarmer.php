<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Pre-builds the per-server OpenAPI documents (+ per-type JSON Schemas) at
 * `cache:warmup` — every prod deploy — into the cache dir, so the controller serves
 * an `O(file read)` artifact and never builds per request (design D17, the bundle
 * ADR 0077 warmer).
 *
 * It is deliberately **optional** ({@see isOptional()} returns `true`): a docs build
 * failure (a misconfigured resource, an exotic paginator) must never break a deploy,
 * so {@see warmUp()} catches per-server failures, logs them, and carries on. The
 * controller's dev lazy-build is the safety net when an artifact is missing.
 *
 * Symfony may warm into a build/temp dir it later swaps into place, so the warmer
 * writes into the `$cacheDir` it is handed (via {@see ArtifactStore::withCacheDir()}),
 * not the injected runtime store — the controller reads the runtime store, and the
 * two paths line up after the swap.
 *
 * When `json_api.openapi.public_path` is set, the warmer **also** writes a fully
 * static `<public>/<server>.json` (and `.yaml` when `symfony/yaml` is installed) so a
 * web server / CDN can serve the document with zero PHP.
 *
 * In **combined** multi-server mode (`json_api.openapi.multi_server: combined`) the
 * warmer additionally builds the single combined document spanning every server (D5)
 * and stores it under the controller's combined key, so the controller serves it
 * `O(file read)`; a static `combined.json`/`.yaml` is also emitted when a
 * `public_path` is set.
 */
final class DocumentWarmer implements CacheWarmerInterface
{
    /**
     * @param list<string> $servers   the declared server names (`haddowg_json_api.servers`)
     * @param bool         $enabled   `json_api.openapi.enabled` — skip warming entirely when off
     * @param bool         $combined  `json_api.openapi.multi_server === combined` — also warm the combined document
     * @param ?string      $publicPath `json_api.openapi.public_path` — also emit a static file here when set
     */
    public function __construct(
        private readonly DocumentFactory $documents,
        private readonly JsonSchemaFactory $schemas,
        private readonly ArtifactStore $store,
        private readonly array $servers,
        private readonly bool $enabled = true,
        private readonly bool $combined = false,
        private readonly ?string $publicPath = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (!$this->enabled) {
            return [];
        }

        $store = $this->store->withCacheDir($cacheDir);

        foreach ($this->servers as $server) {
            try {
                $this->warmServer($store, $server);
            } catch (\Throwable $e) {
                // A docs failure must not break the deploy (D17): log and continue.
                $this->logger?->error('Failed to warm the OpenAPI document for server "{server}": {message}', [
                    'server' => $server,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        // In combined mode also warm the single document spanning every server (D5),
        // stored under the controller's combined key — the same isolated try/catch so a
        // combined-build failure never breaks the deploy either.
        if ($this->combined) {
            try {
                $this->warmCombined($store);
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to warm the combined OpenAPI document: {message}', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        // No preloadable class files (the artifacts are read at runtime, not opcached).
        return [];
    }

    private function warmServer(ArtifactStore $store, string $server): void
    {
        $document = $this->documents->forServer($server);
        $json = $document->toJsonString(true);
        $store->write($server, $json);

        foreach ($this->schemas->forServer($server) as $type => $schema) {
            $store->writeSchema(
                $server,
                $type,
                (string) \json_encode($schema, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT),
            );
        }

        if ($this->publicPath !== null) {
            $this->writeStatic($server, $document);
        }
    }

    private function warmCombined(ArtifactStore $store): void
    {
        $document = $this->documents->combined();
        $store->write(\haddowg\JsonApiBundle\Controller\OpenApiController::COMBINED_KEY, $document->toJsonString(true));

        if ($this->publicPath !== null) {
            // A stable static filename for the combined document (the combined key is a
            // bracketed token, so a fixed name keeps the static artifact CDN-friendly).
            $this->writeStatic('combined', $document);
        }
    }

    private function writeStatic(string $server, \haddowg\JsonApi\OpenApi\OpenApi $document): void
    {
        $base = \rtrim((string) $this->publicPath, '/');
        if (!\is_dir($base) && !@\mkdir($base, 0o777, true) && !\is_dir($base)) {
            throw new \RuntimeException(\sprintf('Could not create the OpenAPI public_path directory "%s".', $base));
        }

        \file_put_contents($base . '/' . $this->safe($server) . '.json', $document->toJsonString(true));

        // YAML is emitted only when symfony/yaml is installed (it is a soft, suggested
        // dependency, gated like the export command's --format=yaml).
        if (\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $yaml = \Symfony\Component\Yaml\Yaml::dump($document->toArray(), 16, 2, \Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP);
            \file_put_contents($base . '/' . $this->safe($server) . '.yaml', $yaml);
        }
    }

    private function safe(string $name): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return ($safe === null || $safe === '') ? '_' : $safe;
    }
}
