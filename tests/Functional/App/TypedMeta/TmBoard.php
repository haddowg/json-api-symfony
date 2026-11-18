<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A board of cards, with one pinned card. Its own documents declare no meta, but its
 * related endpoints serve cards, whose documents do.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tm_board')]
class TmBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    /** @var Collection<int, TmCard> */
    #[ORM\OneToMany(targetEntity: TmCard::class, mappedBy: 'board')]
    public Collection $cards;

    #[ORM\ManyToOne(targetEntity: TmCard::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?TmCard $pinned = null;

    public function __construct()
    {
        $this->cards = new ArrayCollection();
    }
}
