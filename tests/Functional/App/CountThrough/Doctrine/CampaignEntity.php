<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ct_campaign')]
class CampaignEntity
{
    /**
     * @var Collection<int, LeadEntity>
     */
    #[ORM\OneToMany(targetEntity: LeadEntity::class, mappedBy: 'campaign')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $leads;

    /**
     * @var Collection<int, LeadEntity>
     */
    #[ORM\ManyToMany(targetEntity: LeadEntity::class)]
    #[ORM\JoinTable(name: 'ct_campaign_shortlist')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $shortlist;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
        #[ORM\ManyToOne(targetEntity: BrandEntity::class)]
        #[ORM\JoinColumn(name: 'brand_id', referencedColumnName: 'id', nullable: true)]
        public ?BrandEntity $brand = null,
    ) {
        $this->leads = new ArrayCollection();
        $this->shortlist = new ArrayCollection();
    }
}
