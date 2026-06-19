<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Controller;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the OpenAPI 3.1 document for a server (design §3, D9/D17):
 * `GET {json.path}` (default `/docs.json`) for the implicit `default` server and
 * `GET /{server}/docs.json` for a named one.
 *
 * It serves the **pre-built artifact** the {@see \haddowg\JsonApiBundle\OpenApi\DocumentWarmer}
 * wrote at `cache:warmup` (an `O(file read)`, never a per-request build). When the
 * artifact is absent — `kernel.debug` (resources change between edits, no warmup), or
 * a deploy where the optional warmer was skipped/failed — it **lazy-builds** via the
 * {@see DocumentFactory}, and (in debug only) caches the result so the next request
 * is served from disk. Lazy writes are skipped outside debug so a read-only prod
 * filesystem is never written to.
 *
 * The document is **OpenAPI JSON, not a JSON:API document**, so it is served as
 * `application/json` (a `Content-Type: application/vnd.api+json` would be wrong — the
 * doc is not a JSON:API resource). These routes carry no JSON:API route marker, so
 * the bundle's exception listener does not own their errors.
 *
 * The route is registered only when `kernel.debug` is true **or**
 * `json_api.openapi.expose_in_prod` is true (the loader's expose gate, D9), so the
 * controller need not re-check exposure.
 *
 * In **combined** multi-server mode (`json_api.openapi.multi_server: combined`) the
 * loader emits only the json-path route, and this controller serves the single
 * combined document spanning every server (D5) — the {@see DocumentFactory::combined()}
 * build, stored under {@see ArtifactStore} the combined key — regardless of the route's
 * `server` default.
 */
final class OpenApiController
{
    /**
     * The reserved artifact key the combined document is warmed/served under, distinct
     * from any per-server key (a JSON:API server name is never an empty-bracket token).
     */
    public const string COMBINED_KEY = '[combined]';

    public function __construct(
        private readonly DocumentFactory $documents,
        private readonly ArtifactStore $store,
        private readonly bool $debug = false,
        private readonly bool $combined = false,
    ) {}

    /**
     * Serves the document for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined document in combined mode.
     */
    public function __invoke(?string $server = null): Response
    {
        $key = $this->combined ? self::COMBINED_KEY : ($server ?? ServerProvider::DEFAULT_SERVER);

        $json = $this->store->read($key) ?? $this->build($key);

        return new Response(
            $json,
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }

    private function build(string $key): string
    {
        $document = $this->combined ? $this->documents->combined() : $this->documents->forServer($key);
        $json = $document->toJsonString(true);

        // Cache the lazy build only in debug — never write to a (possibly read-only)
        // prod filesystem from a request.
        if ($this->debug) {
            $this->store->write($key, $json);
        }

        return $json;
    }
}
