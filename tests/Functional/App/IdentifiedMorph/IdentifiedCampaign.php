<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph;

use Doctrine\ORM\Mapping as ORM;

/**
 * A campaign that stores its target as a plain type and id column pair, with no
 * Doctrine association.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identified_campaign')]
class IdentifiedCampaign
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $targetType = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $targetId = null,
    ) {}
}
