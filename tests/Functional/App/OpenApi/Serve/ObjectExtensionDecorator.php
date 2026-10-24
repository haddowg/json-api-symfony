<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface;

/**
 * Puts an object into the document's values (a `DateTimeImmutable` in an `info`
 * extension), which the serve artifact can only cache once its class is allow-listed.
 */
final class ObjectExtensionDecorator implements OpenApiFactoryInterface
{
    public function decorate(OpenApi $document, string $server): OpenApi
    {
        return $document->withInfo($document->info->withExtension('x-released', new \DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }
}
