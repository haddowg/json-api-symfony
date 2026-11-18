<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kd_division')]
class DivisionEntity
{
    /**
     * @var Collection<int, TeamEntity>
     */
    #[ORM\OneToMany(targetEntity: TeamEntity::class, mappedBy: 'division')]
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
