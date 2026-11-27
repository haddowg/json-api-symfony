<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use Doctrine\ORM\Mapping as ORM;

/**
 * A row seating its `peer` beside its `owner`, two owners of the same class.
 */
#[ORM\Entity]
#[ORM\Table(name: 'st_uuid_seat')]
class StUuidSeat
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: StUuidOwner::class, inversedBy: 'seats')]
        #[ORM\JoinColumn(nullable: false)]
        public ?StUuidOwner $owner = null,
        #[ORM\ManyToOne(targetEntity: StUuidOwner::class, inversedBy: 'peerSeats')]
        #[ORM\JoinColumn(nullable: false)]
        public ?StUuidOwner $peer = null,
        #[ORM\Column(type: 'integer')]
        public int $rank = 0,
    ) {}
}
