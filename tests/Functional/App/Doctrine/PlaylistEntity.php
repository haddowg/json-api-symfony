<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The parent side of the `belongsToMany` pivot fixture. A plain `#[ORM\ManyToMany]`
 * join table to {@see TrackEntity} could not carry the `position`/`addedAt` pivot
 * columns, so the join is modelled as an **association entity**
 * ({@see PlaylistTrackEntity}): this parent owns a single `OneToMany` to it, and
 * that entity has a `ManyToOne` to the far {@see TrackEntity}.
 *
 * Exactly ONE to-many association reaches the far type, so the `playlists.tracks`
 * pivot relation **auto-detects** its association entity — no `->through()` needed
 * (the default; the ambiguous case is the {@see AlbumEntity} witness). Not `final`
 * so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playlist')]
class PlaylistEntity
{
    /**
     * @var Collection<int, PlaylistTrackEntity>
     */
    #[ORM\OneToMany(targetEntity: PlaylistTrackEntity::class, mappedBy: 'playlist')]
    public Collection $playlistTracks;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->playlistTracks = new ArrayCollection();
    }
}
