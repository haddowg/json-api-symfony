<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind a crew's `staff`, carrying the stored `shift` and `note`
 * columns.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_shift')]
class CpShift
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: CpCrew::class, inversedBy: 'shifts')]
        #[ORM\JoinColumn(nullable: false)]
        public CpCrew $crew,
        #[ORM\ManyToOne(targetEntity: CpMember::class)]
        #[ORM\JoinColumn(nullable: false)]
        public CpMember $member,
        #[ORM\Column(type: 'integer')]
        public int $shift = 0,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $note = null,
    ) {}
}
