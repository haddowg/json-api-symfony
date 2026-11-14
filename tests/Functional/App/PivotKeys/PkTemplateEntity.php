<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A campaign template keyed by its campaign: the single identifier field is a
 * OneToOne association, stored in the `campaignId` column.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pk_template')]
class PkTemplateEntity
{
    #[ORM\Column]
    public string $name = '';

    /**
     * @var Collection<int, PkTemplatePlaceholderEntity>
     */
    #[ORM\OneToMany(targetEntity: PkTemplatePlaceholderEntity::class, mappedBy: 'template')]
    public Collection $placeholderRows;

    /**
     * @var Collection<int, PkPinnedPlaceholderEntity>
     */
    #[ORM\OneToMany(targetEntity: PkPinnedPlaceholderEntity::class, mappedBy: 'template')]
    public Collection $pinnedRows;

    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: PkCampaignEntity::class)]
        #[ORM\JoinColumn(name: 'campaignId', referencedColumnName: 'id', nullable: false)]
        public PkCampaignEntity $campaign,
    ) {
        $this->placeholderRows = new ArrayCollection();
        $this->pinnedRows = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->campaign->id;
    }
}
