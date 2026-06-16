<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * One of the two association entities reaching {@see TrackEntity} from an
 * {@see AlbumEntity}, present to make pivot auto-detection ambiguous (see
 * {@see AlbumEntity}). Carries a `position` pivot column. Not `final` so Doctrine
 * may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'album_track')]
class AlbumTrackEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: AlbumEntity::class, inversedBy: 'albumTracks')]
        public ?AlbumEntity $album = null,
        #[ORM\ManyToOne(targetEntity: TrackEntity::class)]
        public ?TrackEntity $track = null,
        #[ORM\Column(type: 'integer')]
        public int $position = 0,
    ) {}
}
