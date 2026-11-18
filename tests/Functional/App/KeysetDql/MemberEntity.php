<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kd_member')]
class MemberEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: TeamEntity::class)]
        #[ORM\JoinColumn(nullable: false)]
        public ?TeamEntity $team = null,
        #[ORM\Column]
        public int $score = 0,
    ) {}
}
