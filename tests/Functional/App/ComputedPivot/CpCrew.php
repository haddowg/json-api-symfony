<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * `members` is a plain join table, so its pivot can only be computed. `shifts` is an
 * association entity carrying a stored pivot column, which only the Doctrine kernel serves.
 * The in-memory store keeps `members` as a list, Doctrine as a collection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cp_crew')]
class CpCrew
{
    /**
     * @var iterable<CpMember>
     */
    #[ORM\ManyToMany(targetEntity: CpMember::class)]
    #[ORM\JoinTable(name: 'cp_crew_member')]
    public iterable $members;

    /**
     * @var Collection<int, CpShift>
     */
    #[ORM\OneToMany(targetEntity: CpShift::class, mappedBy: 'crew')]
    public Collection $shifts;

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
        array $members = [],
    ) {
        $this->members = new ArrayCollection($members);
        $this->shifts = new ArrayCollection();
    }
}
