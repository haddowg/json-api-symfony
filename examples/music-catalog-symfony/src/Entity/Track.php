<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A track — the Doctrine-mapped twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/Track.php Track}.
 *
 * `length_seconds` is the storage column behind the `durationSeconds` field (a
 * `storedAs()` rename on the resource); `genres` is a JSON column behind an
 * `ArrayList`. `playlists` is the owning side of the tracks↔playlists pivot
 * (the inverse `tracks` lives on {@see Playlist}).
 *
 * The id is a database-assigned auto-increment integer (the store-provided default).
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'track')]
class Track
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * The owning side of the tracks↔playlists ManyToMany. The pivot's
     * `position`/`addedAt` fields are declare-only metadata in 1.0, so the
     * association alone backs the relation.
     *
     * @var Collection<int, Playlist>
     */
    #[ORM\ManyToMany(targetEntity: Playlist::class, inversedBy: 'tracks')]
    #[ORM\JoinTable(name: 'playlist_track')]
    public Collection $playlists;

    /**
     * @param list<string> $genres
     */
    public function __construct(
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column(type: 'integer')]
        public int $trackNumber = 0,
        // The storage column the `durationSeconds` field renames via storedAs().
        #[ORM\Column(name: 'length_seconds', type: 'integer')]
        public int $length_seconds = 0,
        #[ORM\Column(type: 'boolean')]
        public bool $explicit = false,
        #[ORM\Column(type: 'json')]
        public array $genres = [],
        #[ORM\Column(name: 'preview_offset', nullable: true)]
        public ?string $previewOffset = null,
        #[ORM\ManyToOne(targetEntity: Album::class, inversedBy: 'tracks')]
        #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: true)]
        public ?Album $album = null,
    ) {
        $this->playlists = new ArrayCollection();
    }
}
