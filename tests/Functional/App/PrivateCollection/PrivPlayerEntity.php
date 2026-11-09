<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PrivateCollection;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'priv_player')]
class PrivPlayerEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PrivSquadEntity::class, inversedBy: 'recruits')]
    #[ORM\JoinColumn(name: 'squad_id', nullable: true)]
    // @phpstan-ignore property.unusedType (only the persister writes it, through reflection)
    private ?PrivSquadEntity $squad = null;

    public function __construct(
        #[ORM\Column]
        public string $name = '',
    ) {}

    public function getSquad(): ?PrivSquadEntity
    {
        return $this->squad;
    }
}
