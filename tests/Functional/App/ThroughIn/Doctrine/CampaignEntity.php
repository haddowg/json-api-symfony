<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wti_campaign')]
class CampaignEntity
{
    /**
     * @var Collection<int, LeadEntity>
     */
    #[ORM\OneToMany(targetEntity: LeadEntity::class, mappedBy: 'campaign')]
    public Collection $leads;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public string $id,
        #[ORM\Column]
        public string $name,
        #[ORM\ManyToOne(targetEntity: AdvertiserEntity::class)]
        #[ORM\JoinColumn(name: 'advertiser_id', referencedColumnName: 'id', nullable: false)]
        public AdvertiserEntity $advertiser,
    ) {
        $this->leads = new ArrayCollection();
    }
}
