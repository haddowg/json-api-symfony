<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface;

/**
 * A test {@see OpenApiFactoryInterface} decorator: it appends a uniquely-named tag
 * carrying the server name to the built document, proving the decorator seam runs over
 * the projected document on every build path (the served lazy-build and the warmed
 * artifact both flow through the DocumentFactory).
 */
final class StampDecorator implements OpenApiFactoryInterface
{
    public const string TAG_NAME = 'X-Decorated';

    public function decorate(OpenApi $document, string $server): OpenApi
    {
        return $document->withTags([
            ...$document->tags,
            new Tag(self::TAG_NAME, 'Stamped by the decorator for server: ' . $server),
        ]);
    }
}
