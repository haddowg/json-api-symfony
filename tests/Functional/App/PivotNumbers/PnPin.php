<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind a board's `pins`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pn_pin')]
class PnPin
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: PnBoard::class, inversedBy: 'pins')]
        #[ORM\JoinColumn(nullable: false)]
        public ?PnBoard $board = null,
        #[ORM\ManyToOne(targetEntity: PnCard::class)]
        #[ORM\JoinColumn(nullable: false)]
        public ?PnCard $card = null,
        #[ORM\Column(type: 'integer')]
        public mixed $count = 0,
        #[ORM\Column(type: 'float')]
        public mixed $weight = 0.0,
    ) {}
}
