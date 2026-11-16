<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fp_card')]
class FpCard
{
    #[ORM\ManyToOne(targetEntity: FpBoard::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: true)]
    public ?FpBoard $board = null;

    #[ORM\ManyToOne(targetEntity: FpDesk::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: true)]
    public ?FpDesk $desk = null;

    /**
     * @var Collection<int, FpComment>
     */
    #[ORM\OneToMany(targetEntity: FpComment::class, mappedBy: 'card')]
    public Collection $comments;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $title = '',
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ?FpBoard $board = null,
        ?FpDesk $desk = null,
    ) {
        $this->comments = new ArrayCollection();
        $this->board = $board;
        $this->desk = $desk;
        $board?->cards->add($this);
        $desk?->cards->add($this);
    }
}
