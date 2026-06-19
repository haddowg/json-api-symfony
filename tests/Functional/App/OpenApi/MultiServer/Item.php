<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\MultiServer;

/**
 * A plain item model shared by the per-server OpenAPI multi-server witness — backs
 * both the `public-items` (default server) and `admin-items` (admin server) providers.
 */
final class Item
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
