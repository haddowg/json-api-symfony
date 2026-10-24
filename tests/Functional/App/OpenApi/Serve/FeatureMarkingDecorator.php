<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Paths;
use haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface;

/**
 * Build-time half of the feature-flag example: marks every `/categories` operation with
 * an `x-feature: categories` extension that {@see FeatureGateDecorator} reads per request.
 */
final class FeatureMarkingDecorator implements OpenApiFactoryInterface
{
    public function decorate(OpenApi $document, string $server): OpenApi
    {
        $items = [];
        foreach ($document->paths->items ?? [] as $path => $item) {
            if (\str_starts_with($path, '/categories')) {
                $operations = [];
                foreach ($item->operations as $method => $operation) {
                    $operations[$method] = $operation->withExtension('x-feature', 'categories');
                }
                $item = $item->withOperations($operations);
            }
            $items[$path] = $item;
        }

        return $document->withPaths(new Paths($items));
    }
}
