<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PrivateCollection;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'priv_squad')]
class PrivSquadEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: PrivPlayerEntity::class)]
    #[ORM\JoinColumn(name: 'captain_id', nullable: true)]
    // @phpstan-ignore property.unusedType (only the persister writes it, through reflection)
    private ?PrivPlayerEntity $captain = null;

    /**
     * @var Collection<int, PrivPlayerEntity>
     */
    #[ORM\ManyToMany(targetEntity: PrivPlayerEntity::class)]
    #[ORM\JoinTable(name: 'priv_squad_players')]
    private Collection $players;

    /**
     * @var Collection<int, PrivPlayerEntity>
     */
    #[ORM\OneToMany(targetEntity: PrivPlayerEntity::class, mappedBy: 'squad')]
    private Collection $recruits;

    public function __construct()
    {
        $this->players = new ArrayCollection();
        $this->recruits = new ArrayCollection();
    }

    public function getCaptain(): ?PrivPlayerEntity
    {
        return $this->captain;
    }

    /**
     * @return Collection<int, PrivPlayerEntity>
     */
    public function getPlayers(): Collection
    {
        return $this->players;
    }

    /**
     * @return Collection<int, PrivPlayerEntity>
     */
    public function getRecruits(): Collection
    {
        return $this->recruits;
    }
}
