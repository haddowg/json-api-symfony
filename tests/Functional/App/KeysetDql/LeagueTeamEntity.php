<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use Doctrine\ORM\Mapping as ORM;

/**
 * A team's place in a league, the association entity the league's `teams` pages through.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kd_league_team')]
class LeagueTeamEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: LeagueEntity::class)]
        public ?LeagueEntity $league = null,
        #[ORM\ManyToOne(targetEntity: TeamEntity::class)]
        public ?TeamEntity $team = null,
        #[ORM\Column]
        public int $slot = 0,
    ) {}
}
