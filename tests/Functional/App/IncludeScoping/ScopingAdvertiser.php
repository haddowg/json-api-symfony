<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The parent of the include-scoping suite. Its `agencies` collection is private with
 * a getter and no setter, so nothing outside the entity can assign it; `partners`
 * starts as the same agencies and is public, for the write cases.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scoping_advertiser')]
class ScopingAdvertiser
{
    /**
     * @var Collection<int, ScopingAgency>
     */
    #[ORM\ManyToMany(targetEntity: ScopingAgency::class)]
    #[ORM\JoinTable(name: 'scoping_advertiser_agency')]
    private Collection $agencies;

    /**
     * The writable twin of `$agencies`, a list once the in-memory persister writes it.
     *
     * @var iterable<int, ScopingAgency>
     */
    #[ORM\ManyToMany(targetEntity: ScopingAgency::class)]
    #[ORM\JoinTable(name: 'scoping_advertiser_partner')]
    public iterable $partners;

    /**
     * @var Collection<int, ScopingPlacement>
     */
    #[ORM\OneToMany(targetEntity: ScopingPlacement::class, mappedBy: 'advertiser')]
    public Collection $placements;

    /**
     * @param list<ScopingAgency> $agencies
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        array $agencies = [],
    ) {
        $this->agencies = new ArrayCollection($agencies);
        $this->partners = new ArrayCollection($agencies);
        $this->placements = new ArrayCollection();
    }

    /**
     * @return Collection<int, ScopingAgency>
     */
    public function getAgencies(): Collection
    {
        return $this->agencies;
    }

    /**
     * The agencies as a plain list: not a Doctrine association.
     *
     * @return list<ScopingAgency>
     */
    public function getAgencyList(): array
    {
        return \array_values($this->agencies->toArray());
    }

    /**
     * The agencies reached through the placements, as the pivot relations read them.
     *
     * @return list<ScopingAgency>
     */
    public function getPlacedAgencies(): array
    {
        return \array_values(\array_map(static fn(ScopingPlacement $placement): ScopingAgency => $placement->agency, $this->placements->toArray()));
    }

    public function addPlacement(ScopingPlacement $placement): void
    {
        $this->placements->add($placement);
    }
}
