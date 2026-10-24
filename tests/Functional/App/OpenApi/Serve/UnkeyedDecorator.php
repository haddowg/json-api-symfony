<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeDecoratorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A serve decorator with no cache key: its presence makes the ETag fall back to a hash of
 * the served body.
 */
final class UnkeyedDecorator implements OpenApiServeDecoratorInterface
{
    public static int $decorated = 0;

    public function decorate(OpenApi $document, Request $request, string $server): OpenApi
    {
        ++self::$decorated;

        return $document->withInfo($document->info->withDescription('Served to ' . $request->getHost()));
    }
}
