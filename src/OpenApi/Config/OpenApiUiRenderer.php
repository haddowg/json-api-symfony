<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Config;

/**
 * The documentation-viewer renderer the UI route embeds (design §6, D6): **one** of
 * Swagger UI or ReDoc, never both — the `json_api.openapi.ui.renderer` choice.
 */
enum OpenApiUiRenderer: string
{
    case Swagger = 'swagger';
    case Redoc = 'redoc';
}
