<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

final class Rim
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
