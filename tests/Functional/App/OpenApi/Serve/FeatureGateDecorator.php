<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeCacheKeyInterface;
use haddowg\JsonApiBundle\OpenApi\OpenApiServeDecoratorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Removes every operation whose `x-feature` the request host's tenant lacks. Every host
 * has `catalog`; a host starting with `full.` also has `catalog-admin` and `categories`.
 */
final class FeatureGateDecorator implements OpenApiServeDecoratorInterface, OpenApiServeCacheKeyInterface
{
    public static int $decorated = 0;

    public static int $keyed = 0;

    public function decorate(OpenApi $document, Request $request, string $server): OpenApi
    {
        ++self::$decorated;
        $enabled = self::features($request);

        $items = [];
        foreach ($document->paths->items ?? [] as $path => $item) {
            $operations = \array_filter(
                $item->operations,
                static function ($operation) use ($enabled): bool {
                    $feature = $operation->extension('x-feature');

                    return !\is_string($feature) || \in_array($feature, $enabled, true);
                },
            );
            $items[$path] = $item->withOperations($operations);
        }

        return $document->withPaths(new Paths($items));
    }

    public function cacheKey(Request $request, string $server): array
    {
        ++self::$keyed;

        return ['v' => 1, 'features' => self::features($request)];
    }

    /**
     * @return list<string>
     */
    public static function features(Request $request): array
    {
        return \str_starts_with($request->getHost(), 'full.') ? ['catalog', 'catalog-admin', 'categories'] : ['catalog'];
    }
}
