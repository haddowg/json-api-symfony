<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'through_crate')]
class CrateEntity
{
    /**
     * @var Collection<int, ParcelEntity>
     */
    #[ORM\OneToMany(targetEntity: ParcelEntity::class, mappedBy: 'crate')]
    public Collection $parcels;

    /**
     * @var Collection<int, CrateMarkEntity>
     */
    #[ORM\OneToMany(targetEntity: CrateMarkEntity::class, mappedBy: 'crate')]
    public Collection $marks;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: VaultEntity::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?VaultEntity $vault = null,
        #[ORM\ManyToOne(targetEntity: SafeEntity::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?SafeEntity $safe = null,
        #[ORM\ManyToOne(targetEntity: LedgerEntity::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?LedgerEntity $ledger = null,
        #[ORM\ManyToOne(targetEntity: LedgerEntity::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?LedgerEntity $auditor = null,
        #[ORM\Column(nullable: true)]
        public ?string $subjectType = null,
        #[ORM\Column(nullable: true)]
        public ?string $subjectId = null,
    ) {
        $this->parcels = new ArrayCollection();
        $this->marks = new ArrayCollection();
    }
}
