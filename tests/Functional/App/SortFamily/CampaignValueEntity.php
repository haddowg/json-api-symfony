<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use Doctrine\ORM\Mapping as ORM;

/**
 * One placeholder value of a campaign, stored as text whatever its type, the way an
 * EAV value table usually is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sf_campaign_value')]
#[ORM\UniqueConstraint(columns: ['campaign_id', 'name'])]
class CampaignValueEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: CampaignEntity::class, inversedBy: 'values')]
        #[ORM\JoinColumn(nullable: false)]
        public ?CampaignEntity $campaign = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column]
        public string $value = '',
    ) {}
}
