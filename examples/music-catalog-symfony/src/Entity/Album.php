<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An album — the Doctrine-mapped twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/Album.php Album}.
 *
 * `published` is a base scope the
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Query\PublishedAlbumsExtension}
 * enforces (a client filter ANDs on top, an unpublished album is a 404). The
 * `releaseInfo` {@see \haddowg\JsonApi\Resource\Field\Map} persists to a single
 * JSON column (rather than the flat label/catalogueNumber columns core's in-memory
 * app spreads it across), mirroring how a Doctrine app stores a structured
 * sub-object. The `availableFrom`/`availableUntil` pair backs the directional
 * `CompareField` (availableUntil GreaterThan availableFrom).
 *
 * The id is a database-assigned auto-increment integer (the store-provided default).
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'album')]
class Album
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * The inverse side of the track→album association: an album's tracks, mapped
     * by {@see Track}'s owning `album` reference.
     *
     * @var Collection<int, Track>
     */
    #[ORM\OneToMany(targetEntity: Track::class, mappedBy: 'album')]
    public Collection $tracks;

    /**
     * @param array<string, mixed>|null $releaseInfo
     */
    public function __construct(
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $averageRating = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $releasedAt = null,
        #[ORM\Column(type: 'boolean')]
        public bool $published = true,
        #[ORM\Column(type: 'boolean')]
        public bool $explicit = false,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $availableFrom = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $availableUntil = null,
        // The structured `releaseInfo` Map persisted to a single JSON column (label
        // + catalogueNumber children), mirroring the in-memory domain's structured
        // sub-object as one stored value.
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $releaseInfo = null,
        #[ORM\ManyToOne(targetEntity: Artist::class, inversedBy: 'albums')]
        #[ORM\JoinColumn(name: 'artist_id', referencedColumnName: 'id', nullable: true)]
        public ?Artist $artist = null,
    ) {
        $this->tracks = new ArrayCollection();
    }
}
