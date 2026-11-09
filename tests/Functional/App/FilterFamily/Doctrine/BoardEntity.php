<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ff_board')]
class BoardEntity
{
    /**
     * @var Collection<int, ItemEntity>
     */
    #[ORM\OneToMany(targetEntity: ItemEntity::class, mappedBy: 'board')]
    public Collection $items;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->items = new ArrayCollection();
    }
}
