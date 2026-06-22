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
 * `BelongsToMany::make('orderedTracks', 'tracks')->fields(Integer::make('position')->required()->min(1), Integer::make('weight')->compareWith('position', Comparison::GreaterThanOrEqual), DateTime::make('addedAt')->readOnly())`.
 *
 * `position` is a **required-on-create** writable field and `weight` a second
 * writable field constrained `weight >= position` — together they back the
 * merge-before-validate witness (bundle ADR 0050): on an existing-member partial
 * pivot update the omitted `position` is preserved from the stored row (no false
 * `422`), and the cross-pivot `weight >= position` rule compares an incoming
 * `weight` against the MERGED (stored) `position`.
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
        // A second WRITABLE pivot field the relation constrains to be >= `position`
        // — a cross-pivot-field rule the merge-before-validate witness exercises over
        // the MERGED pivot row (a partial update sending `weight` alone compares it
        // against the stored `position`). Nullable so a row created without a `weight`
        // in meta inserts cleanly through the constructor-less persister (bundle ADR
        // 0029); the `weight >= position` rule simply does not fire when weight is
        // absent (a comparison needs both values present).
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $weight = null,
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
