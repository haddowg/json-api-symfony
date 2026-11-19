<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'thr_campaign')]
class ThrCampaignEntity
{
    /**
     * @var Collection<int, ThrLeadEntity>
     */
    #[ORM\OneToMany(targetEntity: ThrLeadEntity::class, mappedBy: 'campaign')]
    public Collection $leads;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public string $id,
        #[ORM\Column]
        public string $name,
        #[ORM\Column]
        public int $budget,
        #[ORM\Column(type: 'date_immutable')]
        public \DateTimeImmutable $startDate,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $archivedAt = null,
        #[ORM\ManyToOne(targetEntity: ThrAdvertiserEntity::class, inversedBy: 'campaigns')]
        #[ORM\JoinColumn(name: 'advertiser_id', referencedColumnName: 'id', nullable: true)]
        public ?ThrAdvertiserEntity $advertiser = null,
    ) {
        $this->leads = new ArrayCollection();
    }
}
