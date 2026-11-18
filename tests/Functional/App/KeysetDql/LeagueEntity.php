<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kd_league')]
class LeagueEntity
{
    /**
     * @var Collection<int, TeamEntity>
     */
    #[ORM\ManyToMany(targetEntity: TeamEntity::class)]
    #[ORM\JoinTable(name: 'kd_league_team_link')]
    public Collection $teams;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
    ) {
        $this->teams = new ArrayCollection();
    }
}
