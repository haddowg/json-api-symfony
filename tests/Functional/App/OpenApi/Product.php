<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

/**
 * A plain `products` model for the OpenAPI document witness — backs the in-memory
 * provider so the served document's structure can be asserted against a real,
 * registered resource (paths, components, parameters).
 */
final class Product
{
    /**
     * @param list<string> $tagIds the related `categories` linkage (a to-many)
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $status = 'draft',
        public ?string $categoryId = null,
        public array $tagIds = [],
    ) {}
}
