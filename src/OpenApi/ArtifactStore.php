<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

/**
 * The shared filesystem contract between the {@see DocumentWarmer} (which writes the
 * pre-built OpenAPI documents at `cache:warmup`) and the
 * {@see \haddowg\JsonApiBundle\Controller\OpenApiController} (which serves them) —
 * design D17.
 *
 * It owns the **one** stable sub-path of `%kernel.cache_dir%` both sides agree on, so
 * the controller serves the warmer's artifact with an `O(file read)` (never a
 * per-request build). A per-server JSON document lives at
 * `<cache>/json_api_openapi/<server>.json`; the warmer also drops the per-type
 * JSON Schemas under `<cache>/json_api_openapi/json-schema/<server>/<type>.json`.
 *
 * This is a pure path/IO helper (no projection): the {@see DocumentFactory} builds
 * the document, this stores and loads its serialized form.
 */
final class ArtifactStore
{
    private const SUBDIR = 'json_api_openapi';

    public function __construct(private readonly string $cacheDir) {}

    /**
     * A store rooted at a *different* cache dir — the warmer uses it to write into
     * the `$cacheDir` Symfony passes to `warmUp()` (a build/temp dir it later swaps
     * into place), which is not necessarily the runtime `%kernel.cache_dir%` the
     * controller reads from.
     */
    public function withCacheDir(string $cacheDir): self
    {
        return new self($cacheDir);
    }

    /**
     * The absolute path of the pre-built OpenAPI JSON document for `$server`.
     */
    public function documentPath(string $server): string
    {
        return $this->baseDir() . '/' . $this->safe($server) . '.json';
    }

    /**
     * The pre-built document's JSON string, or null when the warmer has not (yet)
     * written it — the controller's signal to lazy-build (dev) or 404 (prod).
     */
    public function read(string $server): ?string
    {
        $path = $this->documentPath($server);
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Writes `$json` as the pre-built document for `$server`, creating the artifact
     * directory if needed.
     */
    public function write(string $server, string $json): void
    {
        $this->put($this->documentPath($server), $json);
    }

    /**
     * The absolute path of a per-type standalone JSON Schema artifact for
     * `(server, type)`.
     */
    public function schemaPath(string $server, string $type): string
    {
        return $this->baseDir() . '/json-schema/' . $this->safe($server) . '/' . $this->safe($type) . '.json';
    }

    /**
     * Writes a per-type standalone JSON Schema artifact.
     */
    public function writeSchema(string $server, string $type, string $json): void
    {
        $this->put($this->schemaPath($server, $type), $json);
    }

    private function baseDir(): string
    {
        return \rtrim($this->cacheDir, '/') . '/' . self::SUBDIR;
    }

    private function put(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create the OpenAPI artifact directory "%s".', $dir));
        }

        if (\file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(\sprintf('Could not write the OpenAPI artifact "%s".', $path));
        }
    }

    /**
     * Sanitises a server / type name for use as a path segment (it comes from
     * trusted config / registered types, but a defensive whitelist keeps the artifact
     * tree flat and prevents any traversal).
     */
    private function safe(string $name): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return ($safe === null || $safe === '') ? '_' : $safe;
    }
}
