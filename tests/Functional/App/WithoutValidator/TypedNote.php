<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WithoutValidator;

/**
 * A note whose attributes have JSON types to check.
 */
final class TypedNote
{
    public function __construct(
        public ?string $id = null,
        public mixed $title = null,
        public mixed $count = null,
    ) {}
}
