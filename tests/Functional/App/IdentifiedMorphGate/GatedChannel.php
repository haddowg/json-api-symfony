<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use Doctrine\ORM\Mapping as ORM;

/**
 * A channel the scope hides when `$hidden` is set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gated_channel')]
class GatedChannel
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        #[ORM\Column(type: 'boolean')]
        public bool $hidden = false,
    ) {}
}
