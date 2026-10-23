<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An agency the scoping extension hides when `$hidden` is set. Its `members`
 * collection and its `lead` (the inverse of a one-to-one) are private with no setter.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scoping_agency')]
class ScopingAgency
{
    /**
     * @var Collection<int, ScopingMember>
     */
    #[ORM\OneToMany(targetEntity: ScopingMember::class, mappedBy: 'agency')]
    private Collection $members;

    #[ORM\OneToOne(targetEntity: ScopingMember::class, mappedBy: 'leadOf')]
    private ?ScopingMember $lead = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        #[ORM\Column(type: 'boolean')]
        public bool $hidden = false,
    ) {
        $this->members = new ArrayCollection();
    }

    /**
     * @return Collection<int, ScopingMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function getLead(): ?ScopingMember
    {
        return $this->lead;
    }

    public function setLeadFrom(ScopingMember $member): void
    {
        $this->lead = $member;
    }

    public function addMember(ScopingMember $member): void
    {
        $this->members->add($member);
    }
}
