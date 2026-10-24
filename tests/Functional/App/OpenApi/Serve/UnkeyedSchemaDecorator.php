<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve;

use haddowg\JsonApiBundle\OpenApi\JsonSchemaServeDecoratorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A schema serve decorator with no cache key: stamps the request host onto each schema.
 */
final class UnkeyedSchemaDecorator implements JsonSchemaServeDecoratorInterface
{
    public static int $decorated = 0;

    public function decorate(array $schemas, Request $request, string $server): array
    {
        ++self::$decorated;
        foreach ($schemas as $schema) {
            $schema->{'x-served-to'} = $request->getHost();
        }

        return $schemas;
    }
}
