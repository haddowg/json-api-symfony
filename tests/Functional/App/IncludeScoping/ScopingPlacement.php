<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind the advertiser's pivot relations, carrying a `role`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scoping_placement')]
class ScopingPlacement
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\ManyToOne(targetEntity: ScopingAdvertiser::class, inversedBy: 'placements')]
        public ScopingAdvertiser $advertiser,
        #[ORM\ManyToOne(targetEntity: ScopingAgency::class)]
        public ScopingAgency $agency,
        #[ORM\Column(type: 'string')]
        public string $role,
    ) {
        $advertiser->addPlacement($this);
    }
}
