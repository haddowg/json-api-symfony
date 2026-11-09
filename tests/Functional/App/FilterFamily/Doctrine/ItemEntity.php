<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ff_item')]
class ItemEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\ManyToOne(targetEntity: BoardEntity::class, inversedBy: 'items')]
        public BoardEntity $board,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column]
        public string $label = '',
        #[ORM\Column]
        public string $color = '',
        #[ORM\Column]
        public string $size = '',
        #[ORM\Column]
        public int $weight = 0,
    ) {}
}
