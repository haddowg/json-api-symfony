<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use Doctrine\ORM\Mapping as ORM;

/**
 * A link row of a board's `links`. Its numbers are untyped, so the in-memory store keeps
 * whatever PHP type the write gave them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pn_link')]
class PnLink
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: PnBoard::class, inversedBy: 'links')]
        #[ORM\JoinColumn(nullable: false)]
        public ?PnBoard $board = null,
        #[ORM\Column(type: 'string')]
        public string $itemType = '',
        #[ORM\Column(type: 'string')]
        public string $itemId = '',
        #[ORM\Column(type: 'integer')]
        public mixed $count = 0,
        #[ORM\Column(type: 'float')]
        public mixed $weight = 0.0,
    ) {}
}
