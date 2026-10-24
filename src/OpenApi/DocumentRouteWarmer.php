<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApiBundle\Controller\OpenApiController;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Scans the routes for each server's document route at `cache:warmup` and writes the
 * result beside the documents, so no request scans ({@see DocumentRouteLocator}).
 *
 * A server whose document no route serves gets no `describedby` on its links. That is
 * the intent when the expose gate is closed, so it passes silently; with the gate open
 * it's a misconfiguration, so it logs a warning naming the server. A server with no
 * JSON:API routes mounted renders no links, so the scan leaves it out and it never warns. Like the
 * {@see DocumentWarmer} it's optional and never fails the warmup, and it runs before it,
 * so the documents it builds link through this scan.
 */
final class DocumentRouteWarmer implements CacheWarmerInterface
{
    /**
     * @param bool $enabled `json_api.openapi.enabled` and `json_api.openapi.describedby` on, and no `document_url` configured
     * @param bool $exposed the expose gate: `kernel.debug` or `json_api.openapi.expose_in_prod`
     */
    public function __construct(
        private readonly DocumentRouteLocator $locator,
        private readonly ArtifactStore $store,
        private readonly bool $enabled = true,
        private readonly bool $exposed = true,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $routes = $this->locator->rescan();
            $this->store->withCacheDir($cacheDir)->writeDocumentRoutes($routes);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to find the OpenAPI document routes: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }

        if (!$this->exposed) {
            return [];
        }

        foreach ($routes as $server => $route) {
            if ($route !== null) {
                continue;
            }

            if ($server === OpenApiController::COMBINED_KEY) {
                $this->logger?->warning('No route serves the combined OpenAPI document, so links carry no describedby. Set json_api.openapi.document_url, or register the document route: import the jsonapi_openapi routes, or route a path to {controller}.', [
                    'controller' => OpenApiController::class,
                ]);

                continue;
            }

            $this->logger?->warning('No route serves the OpenAPI document for server "{server}", so its links carry no describedby. Set json_api.openapi.document_url, or register the document route: import the jsonapi_openapi routes, or route a path to {controller} with a "server" default of "{server}".', [
                'server' => $server,
                'controller' => OpenApiController::class,
            ]);
        }

        return [];
    }
}
