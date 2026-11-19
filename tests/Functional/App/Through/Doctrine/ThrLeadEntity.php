<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'thr_lead')]
class ThrLeadEntity
{
    /**
     * @var Collection<int, ThrTemplateEntity>
     */
    #[ORM\ManyToMany(targetEntity: ThrTemplateEntity::class)]
    #[ORM\JoinTable(name: 'thr_lead_templates')]
    public Collection $templates;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
        #[ORM\ManyToOne(targetEntity: ThrCampaignEntity::class, inversedBy: 'leads')]
        #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: true)]
        public ?ThrCampaignEntity $campaign = null,
    ) {
        $this->templates = new ArrayCollection();
    }
}
