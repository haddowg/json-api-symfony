<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationBounds;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The in-memory store keeps `players` as a list, Doctrine as a collection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rb_squad')]
class Squad
{
    /**
     * @var iterable<Player>
     */
    #[ORM\ManyToMany(targetEntity: Player::class)]
    #[ORM\JoinTable(name: 'rb_squad_player')]
    public iterable $players;

    /**
     * @param list<Player> $players
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'string')]
        public ?string $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
        array $players = [],
    ) {
        $this->players = new ArrayCollection($players);
    }
}
