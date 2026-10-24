<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifactCodec;

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

    public const string PACKAGE = 'haddowg/json-api-symfony';

    private readonly ServeArtifactCodec $codec;

    /**
     * @param list<class-string> $allowedClasses `json_api.openapi.serve_allowed_classes`: classes a document's values may hold besides core's
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly array $allowedClasses = [],
    ) {
        $this->codec = new ServeArtifactCodec([self::PACKAGE], $allowedClasses);
    }

    /**
     * A store rooted at a *different* cache dir — the warmer uses it to write into
     * the `$cacheDir` Symfony passes to `warmUp()` (a build/temp dir it later swaps
     * into place), which is not necessarily the runtime `%kernel.cache_dir%` the
     * controller reads from.
     */
    public function withCacheDir(string $cacheDir): self
    {
        return new self($cacheDir, $this->allowedClasses);
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
     * written it — the controller's signal to lazy-build via the {@see DocumentFactory}
     * (in every environment; it only persists the lazy result back to disk under
     * `kernel.debug`).
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
     * The hash of the document artifact, written beside it so a conditional request costs
     * one small read. Null when it was never written.
     */
    public function readHash(string $server): ?string
    {
        return $this->get($this->baseDir() . '/' . $this->safe($server) . '.hash');
    }

    /**
     * Writes the document artifact and its hash together, returning the hash.
     */
    public function writeWithHash(string $server, string $json): string
    {
        $hash = self::hash($json);
        $this->write($server, $json);
        $this->put($this->baseDir() . '/' . $this->safe($server) . '.hash', $hash);

        return $hash;
    }

    /**
     * The warmed document as value objects (bundle ADR 0131), or null when it was never
     * written, was written by another installed version of core or this bundle, or
     * doesn't decode.
     */
    public function readServeArtifact(string $server): ?ServeArtifact
    {
        $bytes = $this->get($this->serveArtifactPath($server));

        return $bytes === null ? null : $this->codec->decode($bytes);
    }

    /**
     * @throws \LogicException when the document holds an object outside the allow-list
     */
    public function writeServeArtifact(string $server, ServeArtifact $artifact): void
    {
        $this->put($this->serveArtifactPath($server), $this->codec->encode($artifact));
    }

    /**
     * Removes the serialized document, so a request whose document can't be serialized
     * never reads one an earlier warmup left behind.
     */
    public function removeServeArtifact(string $server): void
    {
        $path = $this->serveArtifactPath($server);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    /**
     * The operations the warmed document carries, as path template → lower-case methods:
     * the input of the `describedby` resolver, so a request never loads the whole document
     * to point a link at it. Null when it was never written or can't be read.
     *
     * @return array<string, list<string>>|null
     */
    public function readOperations(string $server): ?array
    {
        $json = $this->get($this->operationsPath($server));
        if ($json === null) {
            return null;
        }

        try {
            $operations = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($operations)) {
            return null;
        }

        $map = [];
        foreach ($operations as $path => $methods) {
            if (!\is_string($path) || !\is_array($methods)) {
                return null;
            }
            $map[$path] = \array_values(\array_filter($methods, \is_string(...)));
        }

        return $map;
    }

    /**
     * @param array<string, list<string>> $operations
     */
    public function writeOperations(string $server, array $operations): void
    {
        $this->put($this->operationsPath($server), (string) \json_encode((object) $operations, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * The hash of the aggregate JSON Schema artifact, or null when it was never written.
     */
    public function readSchemaAggregateHash(string $server): ?string
    {
        return $this->get($this->baseDir() . '/json-schema/' . $this->safe($server) . '.hash');
    }

    /**
     * Writes the aggregate JSON Schema artifact and its hash together, returning the hash.
     */
    public function writeSchemaAggregateWithHash(string $server, string $json): string
    {
        $hash = self::hash($json);
        $this->writeSchemaAggregate($server, $json);
        $this->put($this->baseDir() . '/json-schema/' . $this->safe($server) . '.hash', $hash);

        return $hash;
    }

    /**
     * The content hash every artifact and served body is tagged with.
     */
    public static function hash(string $bytes): string
    {
        return EntityTag::hash($bytes);
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

    /**
     * The absolute path of the aggregate JSON Schema artifact for `$server` — the one
     * object keyed by type the {@see \haddowg\JsonApiBundle\Controller\JsonSchemaController}
     * serves. It sits beside the per-type `<server>/` directory (`<server>.json` vs the
     * `<server>` dir), so the two never collide.
     */
    public function schemaAggregatePath(string $server): string
    {
        return $this->baseDir() . '/json-schema/' . $this->safe($server) . '.json';
    }

    /**
     * The pre-built aggregate JSON Schema document for `$server`, or null when the
     * warmer has not (yet) written it — the controller's signal to lazy-build (in
     * every environment; it persists the result only under `kernel.debug`).
     */
    public function readSchemaAggregate(string $server): ?string
    {
        $path = $this->schemaAggregatePath($server);
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Writes the aggregate JSON Schema document (one object keyed by type) for `$server`.
     */
    public function writeSchemaAggregate(string $server, string $json): void
    {
        $this->put($this->schemaAggregatePath($server), $json);
    }

    private function operationsPath(string $server): string
    {
        return $this->baseDir() . '/' . $this->safe($server) . '.operations.json';
    }

    private function serveArtifactPath(string $server): string
    {
        return $this->baseDir() . '/' . $this->safe($server) . '.serve';
    }

    private function get(string $path): ?string
    {
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);

        return $contents === false ? null : $contents;
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

    private function safe(string $name): string
    {
        return self::sanitizeSegment($name);
    }

    /**
     * Sanitises a server / type name for use as a single path segment (it comes from
     * trusted config / registered types, but a defensive whitelist keeps the artifact
     * tree flat and prevents any traversal). Shared with the JSON-Schema export command
     * so a CLI directory dump names its files by the same rule the warmer/controller use.
     */
    public static function sanitizeSegment(string $name): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return ($safe === null || $safe === '') ? '_' : $safe;
    }
}
