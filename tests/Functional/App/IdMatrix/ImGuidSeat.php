<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;

/**
 * A seat linking a guid owner to a uuid owner, carrying a stored `rank`. The owner's
 * foreign key holds the guid as the owner stores it, in whatever case that is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_guid_seat')]
class ImGuidSeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ImGuidOwner::class, inversedBy: 'seats')]
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false)]
        public ImGuidOwner $owner,
        #[ORM\ManyToOne(targetEntity: ImUuidOwner::class)]
        #[ORM\JoinColumn(name: 'peer_id', referencedColumnName: 'id', nullable: false)]
        public ImUuidOwner $peer,
        #[ORM\Column(type: 'integer')]
        public int $rank = 0,
    ) {}
}
