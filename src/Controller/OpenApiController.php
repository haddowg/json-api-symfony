<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Controller;

use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\UnserializableValue;
use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeDecoratorInterface;
use haddowg\JsonApiBundle\OpenApi\Serve\ConditionalResponder;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * With no {@see OpenApiServeDecoratorInterface} registered the artifact's bytes are served
 * as they are, tagged with the hash the warmer stored beside them, so a matching
 * `If-None-Match` costs one small read. With serve decorators it loads the warmed value
 * objects, runs the decorators with the request, prunes what they orphaned and serialises
 * the result (bundle ADR 0077).
 */
final class OpenApiController
{
    /**
     * The reserved artifact key the combined document is warmed/served under, distinct
     * from any per-server key (a JSON:API server name is never an empty-bracket token).
     */
    public const string COMBINED_KEY = '[combined]';

    /** @var list<OpenApiServeDecoratorInterface> */
    private readonly array $decorators;

    private readonly bool $keyed;

    private readonly ConditionalResponder $responder;

    /**
     * @param iterable<OpenApiServeDecoratorInterface> $serveDecorators the tagged serve decorators, highest priority first
     * @param ?string                                  $cacheControl    `json_api.openapi.cache_control`
     */
    public function __construct(
        private readonly DocumentFactory $documents,
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
     * Serves the document for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined document in combined mode.
     */
    public function __invoke(Request $request, ?string $server = null): Response
    {
        $key = $this->combined ? self::COMBINED_KEY : ($server ?? ServerProvider::DEFAULT_SERVER);

        // A missing artifact is built at most once per request.
        $built = null;
        $build = function () use (&$built, $key): array {
            return $built ??= $this->build($key);
        };

        if ($this->decorators === []) {
            $json = null;
            $response = $this->responder->prepare($this->artifactHash($key, $json, $build));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }

            return $response->setContent($json ?? $this->store->read($key) ?? $build()[0]);
        }

        $response = null;
        if ($this->keyed) {
            /** @var list<OpenApiServeCacheKeyInterface> $decorators */
            $decorators = $this->decorators;
            $json = null;
            $response = $this->responder->prepare(ConditionalResponder::keyedHash($this->artifactHash($key, $json, $build), $decorators, $request, $key));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        $artifact = $built[1] ?? $this->store->readServeArtifact($key) ?? $build()[1];
        $document = $artifact->document;
        foreach ($this->decorators as $decorator) {
            $document = $decorator->decorate($document, $request, $key);
        }
        $body = (string) \json_encode($artifact->prune($document), ConditionalResponder::JSON_FLAGS);

        if ($response === null) {
            $response = $this->responder->prepare(ArtifactStore::hash($body));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        return $response->setContent($body);
    }

    /**
     * The stored hash of the artifact, or the hash of its bytes (handing them back through
     * `$json`) when no hash was stored.
     *
     * @param \Closure(): array{0: string, 1: ServeArtifact} $build
     */
    private function artifactHash(string $key, ?string &$json, \Closure $build): string
    {
        $hash = $this->store->readHash($key);
        if ($hash !== null) {
            return $hash;
        }

        $json = $this->store->read($key) ?? $build()[0];

        return ArtifactStore::hash($json);
    }

    /**
     * @return array{0: string, 1: ServeArtifact}
     */
    private function build(string $key): array
    {
        $document = $this->combined ? $this->documents->combined() : $this->documents->forServer($key);
        $json = $document->toJsonString(true);
        $artifact = ServeArtifact::of($document);

        // Cache the lazy build only in debug — never write to a (possibly read-only)
        // prod filesystem from a request.
        if ($this->debug) {
            $this->store->writeWithHash($key, $json);
            try {
                $this->store->writeServeArtifact($key, $artifact);
            } catch (UnserializableValue) {
                // The document holds an object the codec won't unserialize, so each
                // decorated request builds it (json_api.openapi.serve_allowed_classes).
            }
        }

        return [$json, $artifact];
    }
}
