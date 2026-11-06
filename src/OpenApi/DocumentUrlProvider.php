<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The URL a server's `describedby` links point into: its OpenAPI document.
 *
 * A configured `json_api.openapi.document_url` is used as given, with `{server}`
 * replaced by the server name, so a document published elsewhere (a docs site, a CDN
 * serving the `public_path` files) can be named absolutely. Otherwise it is the path of
 * the bundle's own document route for the server, or of the app's own route to the
 * {@see \haddowg\JsonApiBundle\Controller\OpenApiController} for it when the bundle's
 * isn't registered ({@see DocumentRouteLocator}), host-relative and including any prefix
 * the routes are imported under. Null when `describedby` is off, generation is disabled,
 * or no route serves the document, so no link points at a document nobody serves.
 */
final readonly class DocumentUrlProvider
{
    public const string SERVER_PLACEHOLDER = '{server}';

    private DocumentUrls $urls;

    /**
     * @param bool    $enabled       `json_api.openapi.enabled` and `json_api.openapi.describedby` both on
     * @param bool    $combined      `json_api.openapi.multi_server === combined`, where every server shares the one document
     * @param ?string $configuredUrl `json_api.openapi.document_url`
     */
    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        private bool $enabled = true,
        private bool $combined = false,
        private ?string $configuredUrl = null,
        ?DocumentRouteLocator $routes = null,
    ) {
        $this->urls = new DocumentUrls($urlGenerator, combined: $combined, routes: $routes);
    }

    public function forServer(?string $server = null): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $server ??= ServerProvider::DEFAULT_SERVER;

        if ($this->configuredUrl !== null) {
            return \str_replace(self::SERVER_PLACEHOLDER, $this->combined ? 'combined' : $server, $this->configuredUrl);
        }

        return $this->urls->json($server);
    }
}
