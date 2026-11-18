<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use Doctrine\ORM\Mapping as ORM;

/**
 * A team whose members only the member table knows, so its top score is only a
 * subquery away.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kd_team')]
class TeamEntity implements ScoredTeam
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(nullable: true)]
        public ?int $base = null,
        #[ORM\Column(nullable: true)]
        public ?int $bonus = null,
        #[ORM\ManyToOne(targetEntity: DivisionEntity::class, inversedBy: 'teams')]
        public ?DivisionEntity $division = null,
    ) {}

    public function base(): ?int
    {
        return $this->base;
    }

    public function bonus(): ?int
    {
        return $this->bonus;
    }

    public function scores(): array
    {
        throw new \LogicException('A team entity does not load its members; the cursor reads the top score from the query.');
    }
}
