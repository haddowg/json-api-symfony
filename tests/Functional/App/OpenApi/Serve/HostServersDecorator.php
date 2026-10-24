<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeDecoratorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Advertises the request host as the document's only server.
 */
final class HostServersDecorator implements OpenApiServeDecoratorInterface, OpenApiServeCacheKeyInterface
{
    public function decorate(OpenApi $document, Request $request, string $server): OpenApi
    {
        return $document->withServers([new Server('https://' . $request->getHost() . '/api')]);
    }

    public function cacheKey(Request $request, string $server): string
    {
        return $request->getHost();
    }
}
