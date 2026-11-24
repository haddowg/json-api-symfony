<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

final class Hub
{
    /**
     * @param list<Spoke> $spokes
     * @param list<Rim>   $rims
     */
    public function __construct(
        public int $id,
        public string $owner,
        public array $spokes = [],
        public array $rims = [],
        public ?Spoke $leadSpoke = null,
    ) {}
}
