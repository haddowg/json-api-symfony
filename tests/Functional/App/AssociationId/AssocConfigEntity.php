<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A config whose primary key is its campaign: the single identifier field is a
 * OneToOne association, so the row's id is the campaign's id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'assoc_config')]
class AssocConfigEntity
{
    #[ORM\Column]
    public string $label = '';

    #[ORM\ManyToOne(targetEntity: AssocRunEntity::class, inversedBy: 'ownedConfigs')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: true)]
    public ?AssocRunEntity $owner = null;

    /**
     * @var Collection<int, AssocRunEntity>
     */
    #[ORM\ManyToMany(targetEntity: AssocRunEntity::class, mappedBy: 'configs')]
    public Collection $runs;

    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: AssocCampaignEntity::class)]
        #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false)]
        public AssocCampaignEntity $campaign,
    ) {
        $this->runs = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->campaign->id;
    }
}
