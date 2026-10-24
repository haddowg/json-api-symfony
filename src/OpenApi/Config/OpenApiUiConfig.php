<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Config;

/**
 * The resolved `json_api.openapi.ui.*` configuration (design §6, D6) — the
 * single config-driven documentation-viewer route's settings.
 *
 * A pure-scalar immutable carrier (so it threads straight through to the controller and
 * route loader as compiled service arguments): whether the viewer route is registered,
 * which renderer the page embeds ({@see OpenApiUiRenderer} — Swagger UI or ReDoc, one
 * not both), the path it mounts at, and an optional CDN base-URL override (null = the
 * controller's pinned bundle default, so a self-hosted/air-gapped asset origin can
 * replace the public CDN), the exact ReDoc version the redoc renderer loads, and whether
 * ReDoc 3.x may send telemetry.
 */
final readonly class OpenApiUiConfig
{
    public function __construct(
        public bool $enabled,
        public OpenApiUiRenderer $renderer,
        public string $path,
        public ?string $cdn,
        public string $redocVersion = \haddowg\JsonApiBundle\Controller\OpenApiUiController::REDOC_VERSION,
        public bool $redocTelemetry = false,
    ) {}
}
