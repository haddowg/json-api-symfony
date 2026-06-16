<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association (pivot) entity backing the `playlists.tracks` `belongsToMany`
 * relation. It carries the pivot columns a plain join table cannot — `position`
 * (an int ordering, a WRITABLE pivot field) and `addedAt` (a server-owned datetime,
 * a readOnly pivot field) — plus the two single-valued (`ManyToOne`) sides:
 * `playlist` back to the parent and `track` to the far type.
 *
 * This is the Doctrine fact the whole pivot feature rests on: pivot data exists
 * only because the join is an entity. The `addedAt` column is server-set on a
 * freshly-created row by the `#[ORM\PrePersist]` callback — it is declared
 * `readOnly()` on the relation, so a value supplied in linkage meta is never written
 * to it; the server owns it. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playlist_track')]
#[ORM\HasLifecycleCallbacks]
class PlaylistTrackEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: PlaylistEntity::class, inversedBy: 'playlistTracks')]
        public ?PlaylistEntity $playlist = null,
        #[ORM\ManyToOne(targetEntity: TrackEntity::class)]
        public ?TrackEntity $track = null,
        #[ORM\Column(type: 'integer')]
        public int $position = 0,
        #[ORM\Column(type: 'datetime_immutable')]
        public ?\DateTimeImmutable $addedAt = null,
    ) {}

    /**
     * Server-sets `addedAt` to "now" when a new association row is persisted without
     * one — the readOnly pivot field's server-owned default. A row whose `addedAt`
     * is already set (the seed rows) keeps its value.
     */
    #[ORM\PrePersist]
    public function stampAddedAt(): void
    {
        $this->addedAt ??= new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
    }
}
