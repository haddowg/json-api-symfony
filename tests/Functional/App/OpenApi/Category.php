<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

/**
 * A plain `categories` model for the OpenAPI document witness — the related type a
 * {@see Product}'s `category` to-one points at; it exists so the document carries the
 * relation's related/relationship endpoints and a second (untagged) resource whose
 * tag falls back to the humanized-type default.
 */
final class Category
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
