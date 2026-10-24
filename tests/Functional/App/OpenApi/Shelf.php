<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

/**
 * The plain model behind the standalone-serializer `shelves` type.
 */
final class Shelf
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
