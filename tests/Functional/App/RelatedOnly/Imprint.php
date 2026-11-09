<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly;

/**
 * A label imprint, reached only through a {@see Record}, with an optional parent imprint.
 */
final class Imprint
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Imprint $parent = null,
    ) {}
}
