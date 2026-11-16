<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fp_board')]
class FpBoard
{
    /**
     * @var Collection<int, FpCard>
     */
    #[ORM\OneToMany(targetEntity: FpCard::class, mappedBy: 'board')]
    public Collection $cards;

    /**
     * @var Collection<int, FpLabel>
     */
    #[ORM\ManyToMany(targetEntity: FpLabel::class)]
    #[ORM\JoinTable(name: 'fp_board_label')]
    public Collection $labels;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->cards = new ArrayCollection();
        $this->labels = new ArrayCollection();
    }
}
