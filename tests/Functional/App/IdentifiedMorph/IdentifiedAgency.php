<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph;

use Doctrine\ORM\Mapping as ORM;

/**
 * An agency the scope hides when `$hidden` is set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identified_agency')]
class IdentifiedAgency
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        #[ORM\ManyToOne(targetEntity: IdentifiedRegion::class)]
        public ?IdentifiedRegion $region = null,
        #[ORM\Column(type: 'boolean')]
        public bool $hidden = false,
    ) {}
}
