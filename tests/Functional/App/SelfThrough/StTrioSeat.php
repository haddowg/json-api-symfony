<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use Doctrine\ORM\Mapping as ORM;

/**
 * A row linking an owner to two uuid owners at once, so nothing tells which of them is the
 * member a relation through it reaches.
 */
#[ORM\Entity]
#[ORM\Table(name: 'st_trio_seat')]
class StTrioSeat
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: StOwner::class)]
        #[ORM\JoinColumn(nullable: false)]
        public ?StOwner $owner = null,
        #[ORM\ManyToOne(targetEntity: StUuidOwner::class)]
        #[ORM\JoinColumn(nullable: false)]
        public ?StUuidOwner $peer = null,
        #[ORM\ManyToOne(targetEntity: StUuidOwner::class)]
        #[ORM\JoinColumn(nullable: false)]
        public ?StUuidOwner $alternate = null,
    ) {}
}
