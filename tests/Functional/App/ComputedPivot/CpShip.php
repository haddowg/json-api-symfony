<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A ship belongs to a crew, so a crew's pivot relations are reached one include deeper
 * through it. Its `members` is a plain join table whose pivot is computed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_ship')]
class CpShip
{
    /**
     * @var iterable<CpMember>
     */
    #[ORM\ManyToMany(targetEntity: CpMember::class)]
    #[ORM\JoinTable(name: 'cp_ship_member')]
    public iterable $members;

    /**
     * @param list<CpMember> $members
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'string')]
        public ?string $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: CpCrew::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?CpCrew $crew = null,
        #[ORM\ManyToOne(targetEntity: CpMember::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?CpMember $captain = null,
        #[ORM\ManyToOne(targetEntity: CpFleet::class, inversedBy: 'ships')]
        #[ORM\JoinColumn(nullable: true)]
        public ?CpFleet $fleet = null,
        array $members = [],
    ) {
        $this->members = new ArrayCollection($members);
    }
}
