<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owner keyed by a `guid` id. On Doctrine its seats link it to uuid owners.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_guid_owner')]
class ImGuidOwner
{
    /**
     * @var Collection<int, ImGuidSeat>
     */
    #[ORM\OneToMany(targetEntity: ImGuidSeat::class, mappedBy: 'owner')]
    public Collection $seats;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public string $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->seats = new ArrayCollection();
    }
}
