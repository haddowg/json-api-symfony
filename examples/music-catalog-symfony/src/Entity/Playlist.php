<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A playlist — the Doctrine-mapped twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/Playlist.php Playlist}.
 *
 * Its `id` is a **UUID** — the id-strategy demonstrator for a string PK the app (not
 * the database) keys. The {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\PlaylistResource}
 * declares `Id::make()->uuid()->generated()`, so the app mints a v4 UUID when a
 * create omits one; the custom
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Hydrator\PlaylistHydrator} also
 * accepts a well-formed client `id` and derives `slug` from `title`.
 *
 * The id is a non-generated string column (no `GeneratedValue`): the value comes
 * from the app/client, not the store. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playlist')]
class Playlist
{
    /**
     * The inverse side of the tracks↔playlists ManyToMany (the owning side lives
     * on {@see Track}). A plain join table — it carries no pivot columns.
     *
     * @var Collection<int, Track>
     */
    #[ORM\ManyToMany(targetEntity: Track::class, mappedBy: 'playlists')]
    public Collection $tracks;

    /**
     * The owning `OneToMany` to the {@see PlaylistEntry} association entity behind
     * the `orderedTracks` pivot relation — the entity that *can* carry the
     * `position`/`addedAt` pivot columns the plain `tracks` join table cannot. This
     * is the only to-many on this entity whose target also has a `ManyToOne` to
     * {@see Track}, so the pivot relation auto-detects it (no `->through()` needed).
     *
     * @var Collection<int, PlaylistEntry>
     */
    #[ORM\OneToMany(targetEntity: PlaylistEntry::class, mappedBy: 'playlist')]
    public Collection $entries;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column]
        public string $slug = '',
        #[ORM\Column(type: 'boolean')]
        public bool $public = false,
        #[ORM\Column(name: 'external_id', nullable: true)]
        public ?string $externalId = null,
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'playlists')]
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: true)]
        public ?User $owner = null,
    ) {
        $this->tracks = new ArrayCollection();
        $this->entries = new ArrayCollection();
    }
}
