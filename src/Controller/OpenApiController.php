<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Controller;

use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\UnserializableValue;
use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\OpenApi\DocumentFormat;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeDecoratorInterface;
use haddowg\JsonApiBundle\OpenApi\Serve\ConditionalResponder;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the OpenAPI 3.1 document for a server:
 * `GET {json.path}` (default `/docs.json`) for the implicit `default` server and
 * `GET /{server}/docs.json` for a named one, and the same document as YAML at
 * `GET {yaml.path}` (default `/docs.yaml`) and `GET /{server}/docs.yaml`.
 *
 * The `format` route default picks the encoding ({@see DocumentFormat}): `json` when a
 * route sets none, or `yaml`. Both are encoded from one JSON body, built the same way, so
 * the YAML document has the same decorators, pruning and cache keys as the JSON one. Its
 * ETag folds the format in, and it is served as `application/yaml`. YAML answers `404`
 * while `json_api.openapi.yaml.enabled` is off, on any route.
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
 * `json_api.openapi.expose_in_prod` is true (the loader's expose gate), so the
 * controller doesn't re-check exposure, for either format. A route an app declares itself
 * is exposed as the app routes it.
 *
 * In **combined** multi-server mode (`json_api.openapi.multi_server: combined`) the
 * loader emits only the json-path and yaml-path routes, and this controller serves the single
 * combined document spanning every server — the {@see DocumentFactory::combined()}
 * build, stored under {@see ArtifactStore} the combined key — regardless of the route's
 * `server` default.
 *
 * With no {@see OpenApiServeDecoratorInterface} registered the artifact's bytes are served
 * as they are, tagged with the hash the warmer stored beside them, so a matching
 * `If-None-Match` costs one small read. With serve decorators it loads the warmed value
 * objects, runs the decorators with the request, prunes what they orphaned and serialises
 * the result.
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
     * @param bool                                     $yaml            `json_api.openapi.enabled` and `json_api.openapi.yaml.enabled` both on
     */
    public function __construct(
        private readonly DocumentFactory $documents,
        private readonly ArtifactStore $store,
        private readonly bool $debug = false,
        private readonly bool $combined = false,
        iterable $serveDecorators = [],
        ?string $cacheControl = null,
        private readonly bool $yaml = false,
    ) {
        $this->decorators = ConditionalResponder::order($serveDecorators);
        $this->keyed = ConditionalResponder::allKeyed($this->decorators);
        $this->responder = new ConditionalResponder($cacheControl);
    }

    /**
     * Serves the document for `$server` (the implicit `default` server when the route
     * carries no `{server}` segment), or the single combined document in combined mode,
     * in the route's `$format`.
     */
    public function __invoke(Request $request, ?string $server = null, string $format = 'json'): Response
    {
        $encoding = $this->encoding($format);
        $key = $this->combined ? self::COMBINED_KEY : ($server ?? ServerProvider::DEFAULT_SERVER);

        // A missing artifact is built at most once per request.
        $built = null;
        $build = function () use (&$built, $key): array {
            return $built ??= $this->build($key);
        };

        if ($this->decorators === []) {
            $json = null;
            $response = $this->prepare($encoding, $this->artifactHash($key, $json, $build));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }

            return $response->setContent($encoding->encode($json ?? $this->store->read($key) ?? $build()[0]));
        }

        $response = null;
        if ($this->keyed) {
            /** @var list<OpenApiServeCacheKeyInterface> $decorators */
            $decorators = $this->decorators;
            $json = null;
            $response = $this->prepare($encoding, ConditionalResponder::keyedHash($this->artifactHash($key, $json, $build), $decorators, $request, $key));
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
            $response = $this->prepare($encoding, ArtifactStore::hash($body));
            if ($this->responder->notModified($response, $request)) {
                return $response;
            }
        }

        return $response->setContent($encoding->encode($body));
    }

    /**
     * The format a route asks for, or a 404 for one this controller doesn't serve.
     */
    private function encoding(string $format): DocumentFormat
    {
        $encoding = DocumentFormat::tryFrom($format);
        if ($encoding === null || ($encoding === DocumentFormat::Yaml && !$this->yaml)) {
            throw new NotFoundHttpException(\sprintf('The OpenAPI document is not served as "%s".', $format));
        }

        return $encoding;
    }

    /**
     * A response tagged for `$encoding` from the hash of the JSON body it encodes.
     */
    private function prepare(DocumentFormat $encoding, string $hash): Response
    {
        $response = $this->responder->prepare($encoding->etag($hash));
        $response->headers->set('Content-Type', $encoding->contentType());

        return $response;
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
