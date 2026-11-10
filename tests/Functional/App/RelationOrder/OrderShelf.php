<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shelf of books, the parent of the ordered relations. The in-memory kernel stores the
 * same class, its collections holding the related objects.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ro_shelf')]
class OrderShelf
{
    /**
     * @var Collection<int, OrderBook>
     */
    #[ORM\OneToMany(targetEntity: OrderBook::class, mappedBy: 'shelf')]
    public Collection $books;

    /**
     * @var Collection<int, OrderPick>
     */
    #[ORM\OneToMany(targetEntity: OrderPick::class, mappedBy: 'shelf', orphanRemoval: true)]
    public Collection $picks;

    /**
     * @var Collection<int, OrderShelfItem>
     */
    #[ORM\OneToMany(targetEntity: OrderShelfItem::class, mappedBy: 'shelf', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $items;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->books = new ArrayCollection();
        $this->picks = new ArrayCollection();
        $this->items = new ArrayCollection();
    }
}
