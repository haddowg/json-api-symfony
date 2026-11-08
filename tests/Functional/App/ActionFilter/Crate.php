<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter;

/**
 * An in-memory crate.
 */
final class Crate
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public string $status = 'open',
        public int $weight = 0,
        public string $lifecycle = 'live',
    ) {}
}
