<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind a board's `pinned` items, carrying the `rank` and
 * `pinnedAt` pivot fields.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ff_pin')]
class PinEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: BoardEntity::class, inversedBy: 'pins')]
        public ?BoardEntity $board = null,
        #[ORM\ManyToOne(targetEntity: ItemEntity::class)]
        public ?ItemEntity $item = null,
        #[ORM\Column]
        public int $rank = 0,
        #[ORM\Column(type: 'datetime_immutable')]
        public ?\DateTimeImmutable $pinnedAt = null,
    ) {}
}
