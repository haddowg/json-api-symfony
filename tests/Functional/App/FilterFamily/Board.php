<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

final class Board
{
    /**
     * @param list<Item> $items
     * @param list<Item> $pinned
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $items = [],
        public array $pinned = [],
    ) {}
}
