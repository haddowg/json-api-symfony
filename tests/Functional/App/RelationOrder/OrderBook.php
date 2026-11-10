<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ro_book')]
class OrderBook
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column]
        public int $year = 0,
        #[ORM\ManyToOne(targetEntity: OrderShelf::class, inversedBy: 'books')]
        public ?OrderShelf $shelf = null,
    ) {}
}
