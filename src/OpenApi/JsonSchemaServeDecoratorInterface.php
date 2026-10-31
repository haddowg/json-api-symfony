<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Adjusts the served `schemas.json` aggregate for one request, the
 * per-type JSON Schema counterpart of {@see OpenApiServeDecoratorInterface}.
 *
 * A service implementing it is autoconfigured onto
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::JSON_SCHEMA_SERVE_DECORATOR_TAG}, ordered by
 * `priority` the same way. The `json-api:json-schema:export` command never runs it.
 * Implement {@see OpenApiServeCacheKeyInterface} as well to answer conditional requests
 * without decorating.
 */
interface JsonSchemaServeDecoratorInterface
{
    /**
     * @param array<string, \stdClass> $schemas the standalone JSON Schema documents keyed by
     *                                          JSON:API type; drop a key to hide that type
     * @param string                   $server  the server name, or
     *                                          {@see \haddowg\JsonApiBundle\Controller\OpenApiController::COMBINED_KEY}
     *                                          in combined mode
     *
     * @return array<string, \stdClass>
     */
    public function decorate(array $schemas, Request $request, string $server): array;
}
