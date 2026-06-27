<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Controller;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the aggregate JSON Schema document for a server alongside the OpenAPI
 * document: `GET {json_schema.path}` (default `/schemas.json`) for the implicit
 * `default` server and `GET /{server}/schemas.json` for a named one.
 *
 * The body is the **standalone per-type JSON Schema 2020-12 documents** the
 * {@see JsonSchemaFactory} builds (the same schemas the `json-api:json-schema:export`
 * command emits), gathered into one object keyed by JSON:API type — a single fetch a
 * client codegen consumes to drive an opt-in request/response validation seam. It
 * mirrors the {@see OpenApiController}: it serves the **pre-built artifact** the
 * {@see \haddowg\JsonApiBundle\OpenApi\DocumentWarmer} wrote at `cache:warmup` (an
 * `O(file read)`), lazy-building via the factory when the artifact is absent and
 * (in debug only) caching the result.
 *
 * Each schema is JSON Schema, not a JSON:API document, so the aggregate is served as
 * `application/json`. The route is registered only behind the same expose gate as the
 * OpenAPI document plus `json_api.openapi.json_schema.enabled`, so the controller need
 * not re-check exposure.
 *
 * In **combined** multi-server mode the loader emits only the json-schema-path route,
 * and this controller serves the single aggregate spanning every server (the
 * {@see JsonSchemaFactory::combined()} build), stored under the
 * {@see OpenApiController::COMBINED_KEY}, regardless of the route's `server` default.
 */
final class JsonSchemaController
{
    public function __construct(
        private readonly JsonSchemaFactory $schemas,
        private readonly ArtifactStore $store,
        private readonly bool $debug = false,
        private readonly bool $combined = false,
    ) {}

    /**
     * Serves the aggregate for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined aggregate in combined mode.
     */
    public function __invoke(?string $server = null): Response
    {
        $key = $this->combined ? OpenApiController::COMBINED_KEY : ($server ?? ServerProvider::DEFAULT_SERVER);

        $json = $this->store->readSchemaAggregate($key) ?? $this->build($key);

        return new Response(
            $json,
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }

    private function build(string $key): string
    {
        $documents = $this->combined ? $this->schemas->combined() : $this->schemas->forServer($key);
        // Cast to an object so an empty server renders `{}`, never `[]`.
        $json = (string) \json_encode((object) $documents, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);

        // Cache the lazy build only in debug — never write to a (possibly read-only)
        // prod filesystem from a request.
        if ($this->debug) {
            $this->store->writeSchemaAggregate($key, $json);
        }

        return $json;
    }
}
