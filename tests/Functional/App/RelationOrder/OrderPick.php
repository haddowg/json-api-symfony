<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Doctrine\ORM\Mapping as ORM;

/**
 * A shelf's pick of a book, the association entity of the pivot `picks` relation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ro_pick')]
#[ORM\UniqueConstraint(columns: ['shelf_id', 'position'])]
class OrderPick
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: OrderShelf::class, inversedBy: 'picks')]
        public ?OrderShelf $shelf = null,
        #[ORM\ManyToOne(targetEntity: OrderBook::class)]
        public ?OrderBook $book = null,
        #[ORM\Column]
        public int $rank = 0,
        #[ORM\Column(nullable: true)]
        public ?int $position = null,
    ) {}
}
