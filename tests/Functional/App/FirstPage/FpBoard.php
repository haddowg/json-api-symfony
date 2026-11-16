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

    /**
     * The board's tagged rows as stored `[type, id]` pairs: board 1 tags label 3, card 2,
     * label 1 and card 5, in that order.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function taggedPairs(): array
    {
        return $this->id === 1 ? [['fp-labels', 3], ['fp-cards', 2], ['fp-labels', 1], ['fp-cards', 5]] : [];
    }
}
