<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Controller;

use haddowg\JsonApiBundle\OpenApi\Config\OpenApiUiRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Serves the documentation viewer page (design §6, D6) — a single config-driven route
 * at `json_api.openapi.ui.path` (default `/docs`) that renders **Swagger UI _or_ ReDoc**
 * (per `json_api.openapi.ui.renderer`, one not both) pointed at the OpenAPI document the
 * {@see OpenApiController} serves.
 *
 * The page is a **plain HTML string** (no Twig — the bundle is deliberately
 * dependency-light and every other controller returns a raw {@see Response}), so the
 * viewer adds zero dependencies. It is **CDN-linked**: it loads the renderer's assets
 * from a pinned public CDN by default, and `json_api.openapi.ui.cdn` swaps that origin
 * for a self-hosted/air-gapped mirror (design §11, the CSP recipe). The spec URL it
 * targets is resolved from the configured json path against the request's base URL, so
 * the page works behind a routing prefix or sub-path mount.
 *
 * The spec URL the page fetches is generated from the **document route** via the router
 * ({@see \haddowg\JsonApiBundle\Routing\OpenApiRouteLoader} registers the default-server /
 * combined document under the `jsonapi.openapi.default` route name), so it honours any
 * routing prefix the app mounts the imported routes under (`->prefix('/api')`) as well as
 * the front-controller script base — the viewer and the document it points at always share
 * the same mount. The configured json path is kept only as a last-resort fallback for the
 * (unexpected) case where the document route is absent.
 *
 * **App-overridability** (design D6): an app that needs a bespoke page registers its own
 * controller on the configured `ui.path` (it imports the docs route loader, so it can
 * instead define its own `GET {ui.path}` route that wins by registration order), or
 * sets `ui.cdn` to retarget the asset origin. The route is registered only when
 * `ui.enabled` is true **and** the expose gate passes (`kernel.debug` or
 * `expose_in_prod`), so this controller never re-checks exposure.
 *
 * The page carries no JSON:API route marker (it is HTML, not a JSON:API operation), so
 * the bundle's exception listener does not own its errors.
 */
final class OpenApiUiController
{
    /**
     * The pinned Swagger UI CDN version — the asset base when no `ui.cdn` override is
     * configured. Bumping it is the single edit to track Swagger UI releases.
     */
    public const string SWAGGER_UI_VERSION = '5.17.14';

    /**
     * The pinned ReDoc CDN version (the standalone bundle), used the same way.
     */
    public const string REDOC_VERSION = '2.1.5';

    private const SWAGGER_CDN = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_UI_VERSION;

    private const REDOC_CDN = 'https://cdn.jsdelivr.net/npm/redoc@' . self::REDOC_VERSION . '/bundles';

    /**
     * The route name of the default-server / combined OpenAPI document, generated to
     * produce the spec URL the page fetches (so it honours any routing prefix).
     */
    public const string DOCUMENT_ROUTE = 'jsonapi.openapi.default';

    /**
     * @param string  $jsonPath the configured `json_api.openapi.json.path` — a last-resort
     *                          fallback for the spec URL, used only if the document route is
     *                          not registered (the router-generated URL is preferred)
     * @param ?string $cdn      the `json_api.openapi.ui.cdn` override (null = the pinned
     *                          renderer default)
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly OpenApiUiRenderer $renderer = OpenApiUiRenderer::Swagger,
        private readonly string $jsonPath = '/docs.json',
        private readonly ?string $cdn = null,
    ) {}

    public function __invoke(Request $request): Response
    {
        $specUrl = $this->specUrl($request);

        $html = $this->renderer === OpenApiUiRenderer::Redoc
            ? $this->redocPage($specUrl)
            : $this->swaggerPage($specUrl);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The spec URL the page fetches — generated from the document route so it honours any
     * routing prefix and the front-controller script base alike. Falls back to the
     * configured json path resolved against the request base URL only if the document
     * route is not registered (e.g. an app that overrode the route loader).
     */
    private function specUrl(Request $request): string
    {
        try {
            return $this->urlGenerator->generate(self::DOCUMENT_ROUTE);
        } catch (RouteNotFoundException) {
            $base = \rtrim($request->getBaseUrl(), '/');

            return $base . '/' . \ltrim($this->jsonPath, '/');
        }
    }

    private function assetBase(string $default): string
    {
        return \rtrim($this->cdn ?? $default, '/');
    }

    private function swaggerPage(string $specUrl): string
    {
        $base = $this->assetBase(self::SWAGGER_CDN);
        $css = $this->attr($base . '/swagger-ui.css');
        $js = $this->attr($base . '/swagger-ui-bundle.js');
        $preset = $this->attr($base . '/swagger-ui-standalone-preset.js');
        $spec = $this->json($specUrl);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>API documentation</title>
              <link rel="stylesheet" href="{$css}">
            </head>
            <body>
              <div id="swagger-ui"></div>
              <script src="{$js}" crossorigin></script>
              <script src="{$preset}" crossorigin></script>
              <script>
                window.addEventListener('load', function () {
                  window.ui = SwaggerUIBundle({
                    url: {$spec},
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
                    layout: 'StandaloneLayout'
                  });
                });
              </script>
            </body>
            </html>
            HTML;
    }

    private function redocPage(string $specUrl): string
    {
        $base = $this->assetBase(self::REDOC_CDN);
        $js = $this->attr($base . '/redoc.standalone.js');
        $spec = $this->attr($specUrl);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>API documentation</title>
              <style>body { margin: 0; padding: 0; }</style>
            </head>
            <body>
              <redoc spec-url="{$spec}"></redoc>
              <script src="{$js}" crossorigin></script>
            </body>
            </html>
            HTML;
    }

    /**
     * HTML-attribute-encode a value (URLs are bundle-config / route-derived, never user
     * input, but escaping keeps the markup well-formed regardless).
     */
    private function attr(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /**
     * JSON-encode a value for safe inline embedding in a `<script>` (escapes the closing
     * tag sequence so a path can never break out of the script context).
     */
    private function json(string $value): string
    {
        return (string) \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES);
    }
}
