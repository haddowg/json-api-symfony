<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface;

/**
 * Marks `GET /products` public with an operation-level `security: []`, the empty list a
 * YAML dump must keep a sequence.
 */
final class PublicOperationDecorator implements OpenApiFactoryInterface
{
    public function decorate(OpenApi $document, string $server): OpenApi
    {
        $item = $document->paths?->items['/products'] ?? null;
        $operation = $item?->operations['get'] ?? null;
        if ($item === null || $operation === null) {
            return $document;
        }

        return $document->withPath('/products', $item->withOperation('get', $operation->withSecurity([])));
    }
}
