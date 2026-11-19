<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'thr_advertiser')]
class ThrAdvertiserEntity
{
    /**
     * @var Collection<int, ThrCampaignEntity>
     */
    #[ORM\OneToMany(targetEntity: ThrCampaignEntity::class, mappedBy: 'advertiser')]
    public Collection $campaigns;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
        #[ORM\Column]
        public bool $hidden = false,
    ) {
        $this->campaigns = new ArrayCollection();
    }
}
