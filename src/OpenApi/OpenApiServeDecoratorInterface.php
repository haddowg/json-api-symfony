<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApi;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adjusts the served OpenAPI document for one request.
 *
 * {@see OpenApiFactoryInterface} runs once per deploy, when the document is built. This
 * interface runs when `docs.json` is served, with the request in hand, so a document can
 * differ per host: absolute `servers` URLs, operations hidden from a tenant that lacks a
 * feature, example hosts rewritten. The CLI export never runs it.
 *
 * A service implementing it is autoconfigured onto
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::OPENAPI_SERVE_DECORATOR_TAG}. Lower
 * `priority` runs first and the highest gets the last word, as with the build-time tag.
 * After the last decorator the bundle drops components, path items and tag definitions
 * that the decorators left unreferenced, so a decorator only removes operations.
 *
 * Implement {@see OpenApiServeCacheKeyInterface} as well so a conditional request can be
 * answered with a 304 before the document is loaded or decorated.
 */
interface OpenApiServeDecoratorInterface
{
    /**
     * @param OpenApi $document the warmed document (after the build-time decorators), or the
     *                          result of a lower-priority serve decorator
     * @param string  $server   the server name, or
     *                          {@see \haddowg\JsonApiBundle\Controller\OpenApiController::COMBINED_KEY}
     *                          in combined mode
     */
    public function decorate(OpenApi $document, Request $request, string $server): OpenApi;
}
