<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association (pivot) entity backing the `playlists.tracks` `belongsToMany`
 * relation. It carries the pivot columns a plain join table cannot — `position`
 * (an int ordering, a WRITABLE pivot field), `weight` (a second WRITABLE int the
 * relation constrains to be >= `position`, exercising a cross-pivot-field rule over
 * the merged meta) and `addedAt` (a server-owned datetime, a readOnly pivot field) —
 * plus the two single-valued (`ManyToOne`) sides: `playlist` back to the parent and
 * `track` to the far type.
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
        // Nullable so a freshly-created row created through the constructor-less
        // persister (ADR 0029) without a `weight` in meta inserts cleanly; the
        // `weight >= position` cross-pivot rule simply does not fire when weight is
        // absent (a comparison needs both values present).
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $weight = null,
        #[ORM\Column(type: 'datetime_immutable')]
        public ?\DateTimeImmutable $addedAt = null,
        // A HIDDEN pivot field's backing column: declared `hidden()` on the relation,
        // so it is filterable/sortable via the `pivot.` prefix but NEVER rendered in
        // the relationship's pivot meta (core hidden() gates rendering only, never
        // query). Nullable so a freshly-created row without one inserts cleanly.
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $note = null,
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
