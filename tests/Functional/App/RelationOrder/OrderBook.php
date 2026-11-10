<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Doctrine\ORM\Mapping as ORM;

/**
 * A book, positioned on its shelf: no two books of a shelf share a position.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ro_book')]
#[ORM\UniqueConstraint(columns: ['shelf_id', 'position'])]
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
        #[ORM\Column(nullable: true)]
        public ?int $position = null,
    ) {}
}
