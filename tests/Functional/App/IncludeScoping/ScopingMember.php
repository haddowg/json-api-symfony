<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member of an agency. Its `agency` is private with a getter and no setter.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scoping_member')]
class ScopingMember
{
    #[ORM\ManyToOne(targetEntity: ScopingAgency::class, inversedBy: 'members')]
    private ?ScopingAgency $agency;

    #[ORM\OneToOne(targetEntity: ScopingAgency::class, inversedBy: 'lead')]
    private ?ScopingAgency $leadOf = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        ?ScopingAgency $agency = null,
        #[ORM\Column(type: 'boolean')]
        public bool $hidden = false,
    ) {
        $this->agency = $agency;
        $agency?->addMember($this);
    }

    public function lead(ScopingAgency $agency): void
    {
        $this->leadOf = $agency;
        $agency->setLeadFrom($this);
    }

    public function getLeadOf(): ?ScopingAgency
    {
        return $this->leadOf;
    }

    public function getAgency(): ?ScopingAgency
    {
        return $this->agency;
    }
}
