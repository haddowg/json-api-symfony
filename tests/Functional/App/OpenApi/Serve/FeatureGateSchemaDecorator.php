<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApiBundle\OpenApi\JsonSchemaServeDecoratorInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drops the `categories` schema for a host whose tenant lacks the `categories` feature.
 */
final class FeatureGateSchemaDecorator implements JsonSchemaServeDecoratorInterface, OpenApiServeCacheKeyInterface
{
    public static int $decorated = 0;

    public function decorate(array $schemas, Request $request, string $server): array
    {
        ++self::$decorated;
        if (!\in_array('categories', FeatureGateDecorator::features($request), true)) {
            unset($schemas['categories']);
        }

        return $schemas;
    }

    public function cacheKey(Request $request, string $server): array
    {
        return ['v' => 1, 'features' => FeatureGateDecorator::features($request)];
    }
}
