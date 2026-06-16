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
 * Its `id` is a client-generated UUID (the resource opts in via
 * `acceptsClientGeneratedId()`, and the custom
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Hydrator\PlaylistHydrator}
 * accepts it); `slug` is derived from `title` by that hydrator.
 *
 * The id is application-assigned (no generator) since the client supplies it.
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playlist')]
class Playlist
{
    /**
     * The inverse side of the tracks↔playlists ManyToMany (the owning side lives
     * on {@see Track}).
     *
     * @var Collection<int, Track>
     */
    #[ORM\ManyToMany(targetEntity: Track::class, mappedBy: 'playlists')]
    public Collection $tracks;

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
    }
}
