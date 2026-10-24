<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Controller;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiBundle\OpenApi\JsonSchemaServeDecoratorInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiBundle\OpenApi\Serve\ConditionalResponder;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * ETags, conditional requests, `Cache-Control` and {@see JsonSchemaServeDecoratorInterface}
 * work as they do for the OpenAPI document (bundle ADR 0131). The schemas are standalone
 * documents with no shared components, so nothing is pruned after decoration.
 */
final class JsonSchemaController
{
    /** @var list<JsonSchemaServeDecoratorInterface> */
    private readonly array $decorators;

    private readonly bool $keyed;

    private readonly ConditionalResponder $responder;

    /**
     * @param iterable<JsonSchemaServeDecoratorInterface> $serveDecorators the tagged serve decorators, highest priority first
     * @param ?string                                     $cacheControl    `json_api.openapi.cache_control`
     */
    public function __construct(
        private readonly JsonSchemaFactory $schemas,
        private readonly ArtifactStore $store,
        private readonly bool $debug = false,
        private readonly bool $combined = false,
        iterable $serveDecorators = [],
        ?string $cacheControl = null,
    ) {
        $this->decorators = ConditionalResponder::order($serveDecorators);
        $this->keyed = ConditionalResponder::allKeyed($this->decorators);
        $this->responder = new ConditionalResponder($cacheControl);
    }

    /**
     * Serves the aggregate for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined aggregate in combined mode.
     */
    public function __invoke(Request $request, ?string $server = null): Response
    {
        $key = $this->combined ? OpenApiController::COMBINED_KEY : ($server ?? ServerProvider::DEFAULT_SERVER);

        $json = null;
        if ($this->decorators === []) {
            $response = $this->responder->prepare($this->artifactHash($key, $json));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }

            return $response->setContent($json ?? $this->store->readSchemaAggregate($key) ?? $this->build($key));
        }

        $response = null;
        if ($this->keyed) {
            /** @var list<OpenApiServeCacheKeyInterface> $decorators */
            $decorators = $this->decorators;
            $response = $this->responder->prepare(ConditionalResponder::keyedHash($this->artifactHash($key, $json), $decorators, $request, $key));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        $json ??= $this->store->readSchemaAggregate($key) ?? $this->build($key);
        $decoded = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, \stdClass> $schemas */
        $schemas = $decoded instanceof \stdClass ? \get_object_vars($decoded) : [];
        foreach ($this->decorators as $decorator) {
            $schemas = $decorator->decorate($schemas, $request, $key);
        }
        // Cast to an object so an empty aggregate renders `{}`, never `[]`.
        $body = (string) \json_encode((object) $schemas, ConditionalResponder::JSON_FLAGS);

        if ($response === null) {
            $response = $this->responder->prepare(ArtifactStore::hash($body));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        return $response->setContent($body);
    }

    /**
     * The stored hash of the aggregate, or the hash of its bytes (handing them back through
     * `$json`) when no hash was stored.
     */
    private function artifactHash(string $key, ?string &$json): string
    {
        $hash = $this->store->readSchemaAggregateHash($key);
        if ($hash !== null) {
            return $hash;
        }

        $json = $this->store->readSchemaAggregate($key) ?? $this->build($key);

        return ArtifactStore::hash($json);
    }

    private function build(string $key): string
    {
        $documents = $this->combined ? $this->schemas->combined() : $this->schemas->forServer($key);
        // Cast to an object so an empty server renders `{}`, never `[]`.
        $json = (string) \json_encode((object) $documents, ConditionalResponder::JSON_FLAGS);

        // Cache the lazy build only in debug — never write to a (possibly read-only)
        // prod filesystem from a request.
        if ($this->debug) {
            $this->store->writeSchemaAggregateWithHash($key, $json);
        }

        return $json;
    }
}
