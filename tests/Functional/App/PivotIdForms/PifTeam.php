<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A team keyed by a binary UUID.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pif_team')]
class PifTeam
{
    /**
     * @var Collection<int, PifSeat>
     */
    #[ORM\OneToMany(targetEntity: PifSeat::class, mappedBy: 'team')]
    public Collection $seats;

    #[ORM\ManyToOne(targetEntity: PifCoach::class)]
    #[ORM\JoinColumn(name: 'coachId', referencedColumnName: 'id', nullable: true)]
    public ?PifCoach $coach = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public Uuid $id,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->seats = new ArrayCollection();
    }
}
