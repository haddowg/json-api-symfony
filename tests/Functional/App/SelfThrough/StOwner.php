<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owner keyed by an integer, seated beside other owners through {@see StSeat} rows.
 * `seats` are the rows it owns and `peerSeats` the rows naming it as the peer. The
 * in-memory store keeps the members themselves in `peers` and `followers`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'st_int_owner')]
class StOwner
{
    /**
     * @var Collection<int, StSeat>
     */
    #[ORM\OneToMany(targetEntity: StSeat::class, mappedBy: 'owner', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $seats;

    /**
     * @var Collection<int, StSeat>
     */
    #[ORM\OneToMany(targetEntity: StSeat::class, mappedBy: 'peer')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $peerSeats;

    /**
     * @var list<self>
     */
    public array $peers = [];

    /**
     * @var list<self>
     */
    public array $followers = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int|string|null $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->seats = new ArrayCollection();
        $this->peerSeats = new ArrayCollection();
    }
}
