<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use Doctrine\ORM\Mapping as ORM;

/**
 * A ship belongs to a crew, so a crew's pivot relations are reached one include deeper
 * through it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_ship')]
class CpShip
{
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
    ) {}
}
