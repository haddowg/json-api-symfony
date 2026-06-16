<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The far (child) side of the `belongsToMany` pivot fixture: a track reachable
 * from a {@see PlaylistEntity} through the {@see PlaylistTrackEntity} association
 * entity (which carries the pivot columns). A plain `#[ORM\ManyToMany]` join could
 * not hold `position`/`addedAt`, so the join is modelled as an association entity
 * and this is the entity it points at. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'track')]
class TrackEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
    ) {}
}
