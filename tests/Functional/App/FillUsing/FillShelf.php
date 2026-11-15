<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shelf whose slots hold the foreign key, so `slots` is an inverse to-many over a type
 * keyed by a string.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fill_shelf')]
class FillShelf
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /**
     * @var Collection<int, FillSlot>
     */
    #[ORM\OneToMany(targetEntity: FillSlot::class, mappedBy: 'shelf')]
    public Collection $slots;

    public function __construct()
    {
        $this->slots = new ArrayCollection();
    }
}
