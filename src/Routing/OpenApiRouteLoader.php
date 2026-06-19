<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Routing;

use haddowg\JsonApiBundle\Controller\OpenApiController;
use haddowg\JsonApiBundle\Controller\OpenApiUiController;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A Symfony route loader for the OpenAPI document endpoints (design §3, D9/D13).
 * An application imports it once — `$routes->import('.', 'jsonapi_openapi')` — exactly
 * as it imports the JSON:API CRUD routes (`'jsonapi'`); both share the import-driven
 * convention, and prefix/host stay in the app's routing config.
 *
 * It emits, **only when exposure is allowed** (the expose gate, D9 — `kernel.debug`
 * is true **or** `json_api.openapi.expose_in_prod` is true), the document routes:
 *  - `GET {json.path}` (default `/docs.json`) → the implicit `default` server's
 *    document;
 *  - `GET /{server}/docs.json` → a named server's document — a single parametric
 *    route whose `{server}` is constrained to the declared non-`default` server names
 *    (so an unknown server `404`s at routing, and the route never shadows the
 *    `default` path).
 *
 * In **combined** multi-server mode (`json_api.openapi.multi_server: combined`) only
 * the single `{json.path}` route is emitted — it serves one document spanning every
 * server (D5); the per-server `{server}/docs.json` route is not registered.
 *
 * It also emits the single config-driven **documentation viewer** route (design D6) at
 * `json_api.openapi.ui.path` (default `/docs`) — when `json_api.openapi.ui.enabled` is
 * true **and** the same expose gate passes — serving the {@see OpenApiUiController}
 * (Swagger UI or ReDoc per config). The viewer rides the same import / prefix / host as
 * the document routes; it points at the configured json path resolved against the
 * request, so per-server / combined doc selection stays the controller's concern.
 *
 * When generation is disabled (`json_api.openapi.enabled: false`) or exposure is not
 * allowed, **no** route is emitted — so the document is unreachable over HTTP exactly
 * as configured (the CLI export stays available regardless, D6/D9). These routes
 * carry **no** JSON:API route marker — the document is OpenAPI JSON, not a JSON:API
 * operation, so the bundle's exception listener does not scope to them.
 */
final class OpenApiRouteLoader extends Loader
{
    public const string ROUTE_TYPE = 'jsonapi_openapi';

    /**
     * @param list<string> $servers      the declared server names (`haddowg_json_api.servers`)
     * @param bool         $enabled      `json_api.openapi.enabled`
     * @param bool         $debug        `kernel.debug` — auto-exposes the routes (D9)
     * @param bool         $exposeInProd `json_api.openapi.expose_in_prod` — exposes outside debug
     * @param bool         $combined     `json_api.openapi.multi_server === combined`
     * @param string       $jsonPath     `json_api.openapi.json.path` (default `/docs.json`)
     * @param bool         $uiEnabled    `json_api.openapi.ui.enabled` — register the viewer route
     * @param string       $uiPath       `json_api.openapi.ui.path` (default `/docs`)
     */
    public function __construct(
        private readonly array $servers,
        private readonly bool $enabled,
        private readonly bool $debug,
        private readonly bool $exposeInProd,
        private readonly bool $combined,
        private readonly string $jsonPath = '/docs.json',
        private readonly bool $uiEnabled = true,
        private readonly string $uiPath = '/docs',
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        // The expose gate (D9): the routes exist when generation is enabled AND
        // (kernel.debug auto-exposes them OR expose_in_prod opts in). Otherwise the
        // document is unreachable over HTTP (the CLI export stays available).
        if (!$this->enabled || (!$this->debug && !$this->exposeInProd)) {
            return $routes;
        }

        // The default server's document at the configured json path (e.g. /docs.json).
        $routes->add('jsonapi.openapi.default', new Route(
            $this->normalisePath($this->jsonPath),
            ['_controller' => OpenApiController::class, 'server' => ServerProvider::DEFAULT_SERVER],
            methods: ['GET'],
        ));

        // The documentation viewer (design D6) — gated on ui.enabled in addition to the
        // shared expose gate. One route regardless of multi-server mode; it points at the
        // configured json path (the controller selects per-server / combined).
        if ($this->uiEnabled) {
            $routes->add('jsonapi.openapi.ui', new Route(
                $this->normaliseUiPath($this->uiPath),
                ['_controller' => OpenApiUiController::class],
                methods: ['GET'],
            ));
        }

        // A combined document spans every server in one doc (D5), so no per-server
        // route is emitted in that mode.
        if ($this->combined) {
            return $routes;
        }

        $named = \array_values(\array_filter(
            $this->servers,
            static fn(string $server): bool => $server !== ServerProvider::DEFAULT_SERVER,
        ));

        if ($named === []) {
            return $routes;
        }

        // One parametric route for every named server, its {server} constrained to the
        // declared names so an unknown server 404s and the default path is never
        // shadowed.
        $route = new Route(
            '/{server}/docs.json',
            ['_controller' => OpenApiController::class],
            ['server' => \implode('|', \array_map('preg_quote', $named))],
            methods: ['GET'],
        );
        $routes->add('jsonapi.openapi.server', $route);

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::ROUTE_TYPE;
    }

    private function normalisePath(string $path): string
    {
        $path = '/' . \ltrim(\trim($path), '/');

        return $path === '/' ? '/docs.json' : $path;
    }

    private function normaliseUiPath(string $path): string
    {
        $path = '/' . \ltrim(\trim($path), '/');

        return $path === '/' ? '/docs' : $path;
    }
}
