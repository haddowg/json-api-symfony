<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Lets a serve decorator ({@see OpenApiServeDecoratorInterface} or
 * {@see JsonSchemaServeDecoratorInterface}) describe its output without producing it.
 *
 * The key must change whenever {@see OpenApiServeDecoratorInterface::decorate()} would
 * return something different for the same warmed document: the request host, the
 * tenant's enabled features, and a version string you bump when the decorator's logic
 * changes. When every registered decorator of a kind implements this interface, the
 * ETag is a hash of the warmed artifact's hash and each key in decorator order, and a
 * matching `If-None-Match` gets a 304 before anything is loaded or decorated. If any
 * decorator of that kind lacks a key, the ETag falls back to a hash of the final body.
 */
interface OpenApiServeCacheKeyInterface
{
    /**
     * @param string $server the server name, or
     *                       {@see \haddowg\JsonApiBundle\Controller\OpenApiController::COMBINED_KEY}
     *                       in combined mode
     *
     * @return array<array-key, mixed>|string a JSON-encodable value
     */
    public function cacheKey(Request $request, string $server): array|string;
}
