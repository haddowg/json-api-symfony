<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Doctrine\ORM\Mapping as ORM;

/**
 * A link row of a shelf's `items`, naming a book or a magazine by type and id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ro_shelf_item')]
class OrderShelfItem
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: OrderShelf::class, inversedBy: 'items')]
        #[ORM\JoinColumn(nullable: false)]
        public ?OrderShelf $shelf = null,
        #[ORM\Column]
        public string $itemType = '',
        #[ORM\Column]
        public int $itemId = 0,
        #[ORM\Column]
        public int $rank = 0,
        #[ORM\Column]
        public string $note = '',
    ) {}

    public function key(): string
    {
        return $this->itemType . ':' . $this->itemId;
    }
}
