<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wti_lead')]
class LeadEntity
{
    /**
     * @var Collection<int, TemplateEntity>
     */
    #[ORM\ManyToMany(targetEntity: TemplateEntity::class)]
    #[ORM\JoinTable(name: 'wti_lead_templates')]
    public Collection $templates;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
        #[ORM\Column]
        public string $status,
        #[ORM\ManyToOne(targetEntity: CampaignEntity::class, inversedBy: 'leads')]
        #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: true)]
        public ?CampaignEntity $campaign = null,
    ) {
        $this->templates = new ArrayCollection();
    }
}
