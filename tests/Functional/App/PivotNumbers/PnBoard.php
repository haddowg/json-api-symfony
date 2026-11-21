<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A board linking cards twice: through the link rows of `links`, which both kernels
 * serve, and through the pivot rows of `pins`, which only Doctrine serves.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pn_board')]
class PnBoard
{
    /**
     * @var Collection<int, PnLink>
     */
    #[ORM\OneToMany(targetEntity: PnLink::class, mappedBy: 'board', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $links;

    /**
     * @var Collection<int, PnPin>
     */
    #[ORM\OneToMany(targetEntity: PnPin::class, mappedBy: 'board', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $pins;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'string')]
        public ?string $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->links = new ArrayCollection();
        $this->pins = new ArrayCollection();
    }
}
