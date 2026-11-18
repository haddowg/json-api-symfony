<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use Doctrine\ORM\Mapping as ORM;

/**
 * A team member. Its team maps no inverse collection, so a team reaches its members only
 * through the foreign key.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fk_member')]
class FkMember
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: FkTeam::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?FkTeam $team = null,
    ) {}
}
