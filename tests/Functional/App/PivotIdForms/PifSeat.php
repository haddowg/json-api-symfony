<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use Doctrine\ORM\Mapping as ORM;

/**
 * The pivot between a team and a player. Its team column is binary, and its player
 * column may hold the player's key in another case than the player's own column.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pif_seat')]
class PifSeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PifTeam::class, inversedBy: 'seats')]
    #[ORM\JoinColumn(name: 'teamId', referencedColumnName: 'id', nullable: false)]
    public ?PifTeam $team = null;

    #[ORM\ManyToOne(targetEntity: PifPlayer::class, inversedBy: 'seats')]
    #[ORM\JoinColumn(name: 'playerId', referencedColumnName: 'id', nullable: false, options: ['collation' => 'NOCASE'])]
    public ?PifPlayer $player = null;

    #[ORM\Column(nullable: true)]
    public ?string $role = null;
}
