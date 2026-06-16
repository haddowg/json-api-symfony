<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association (pivot) entity behind the `playlists.orderedTracks`
 * `belongsToMany` relation — the Doctrine fact the whole pivot feature rests on.
 *
 * A plain `#[ORM\ManyToMany]` join table (such as the {@see Playlist}↔{@see Track}
 * `tracks` relation's `playlist_track` table) holds ONLY the two foreign keys, so
 * Doctrine cannot map a `position` or `addedAt` column on it. To carry pivot data
 * the join must be modelled as a real entity: the parent {@see Playlist} owns a
 * `OneToMany` to this entity, this entity carries the pivot columns plus a
 * `ManyToOne` back to the parent and a `ManyToOne` to the far {@see Track}, and the
 * resource declares the relation as
 * `BelongsToMany::make('orderedTracks')->fields(Integer::make('position')->min(1), DateTime::make('addedAt')->readOnly())`.
 *
 * The bundle's Doctrine adapter renders `position`/`addedAt` as each member's
 * `meta.pivot`, recognises them as `?filter`/`?sort` keys on that relation's
 * related endpoint, and — since the relation declares `position` as a WRITABLE
 * pivot field — UPSERTS the pivot value from the linkage `meta` on a write (add /
 * reorder), all over this entity. See the example README's "Pivot data on an
 * association entity" section.
 *
 * `addedAt` is a `readOnly()` pivot field — server-owned. A freshly-created row
 * gets it stamped by the `#[ORM\PrePersist]` callback below, so a value supplied
 * in linkage `meta` is never written to it (the server owns it).
 *
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playlist_entry')]
#[ORM\HasLifecycleCallbacks]
class PlaylistEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'entries')]
        #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false)]
        public ?Playlist $playlist = null,
        #[ORM\ManyToOne(targetEntity: Track::class)]
        #[ORM\JoinColumn(name: 'track_id', referencedColumnName: 'id', nullable: false)]
        public ?Track $track = null,
        // The pivot columns a plain join table cannot hold: a 1-based ordering and
        // the moment the track was added to the playlist.
        #[ORM\Column(type: 'integer')]
        public int $position = 0,
        #[ORM\Column(name: 'added_at', type: 'datetime_immutable')]
        public ?\DateTimeImmutable $addedAt = null,
    ) {}

    /**
     * Server-sets the `addedAt` pivot column when a NEW association row is
     * persisted without one — the `readOnly()` field's server-owned default. So a
     * track added to the playlist through the linkage `meta` gets a server `addedAt`
     * even though the wire `meta` cannot write it; a row whose `addedAt` is already
     * set (the seed rows, or an in-place reorder) keeps its value.
     */
    #[ORM\PrePersist]
    public function stampAddedAt(): void
    {
        $this->addedAt ??= new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
    }
}
