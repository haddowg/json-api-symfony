<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

final class Item
{
    public function __construct(
        public string $id,
        public string $name,
        public string $label,
        public string $color,
        public string $size,
        public int $weight,
    ) {}
}
