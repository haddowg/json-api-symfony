<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fill_slot')]
class FillSlot
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string')]
        public string $id,
        #[ORM\ManyToOne(targetEntity: FillShelf::class, inversedBy: 'slots')]
        public ?FillShelf $shelf = null,
    ) {}
}
