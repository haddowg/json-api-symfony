<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member of an agency. Its `agency` is private with a getter and no setter; `team`
 * starts as the same agency and is public, for the write cases.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scoping_member')]
class ScopingMember
{
    #[ORM\ManyToOne(targetEntity: ScopingAgency::class, inversedBy: 'members')]
    private ?ScopingAgency $agency = null;

    #[ORM\OneToOne(targetEntity: ScopingAgency::class, inversedBy: 'lead')]
    private ?ScopingAgency $leadOf = null;

    /**
     * The writable twin of `$agency`, starting as the same agency.
     */
    #[ORM\ManyToOne(targetEntity: ScopingAgency::class)]
    #[ORM\JoinColumn(name: 'team_id')]
    public ?ScopingAgency $team = null;

    #[ORM\Column(type: 'boolean')]
    public bool $hidden = false;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'CUSTOM')]
        #[ORM\CustomIdGenerator(class: ScopingIdGenerator::class)]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        ?ScopingAgency $agency = null,
        bool $hidden = false,
    ) {
        $this->hidden = $hidden;
        $this->agency = $agency;
        $this->team = $agency;
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
