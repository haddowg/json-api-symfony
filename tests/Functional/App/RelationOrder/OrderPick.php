<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Doctrine\ORM\Mapping as ORM;

/**
 * A shelf's pick of a book, the association entity of the pivot `picks` relation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ro_pick')]
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
    ) {}
}
