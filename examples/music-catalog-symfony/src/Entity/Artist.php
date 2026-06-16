<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A recording artist — the Doctrine-mapped twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/Artist.php Artist}.
 * The field column names on
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\ArtistResource}
 * match these property names exactly, so the default relation reader returns the
 * mapped associations straight off the entity with no extractor.
 *
 * The id is application-assigned (no generator), matching the string ids the seed
 * uses. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'artist')]
class Artist
{
    /**
     * The inverse side of the album→artist association: an artist's albums,
     * mapped by {@see Album}'s owning `artist` reference.
     *
     * @var Collection<int, Album>
     */
    #[ORM\OneToMany(targetEntity: Album::class, mappedBy: 'artist')]
    public Collection $albums;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column]
        public string $slug = '',
        #[ORM\Column(nullable: true)]
        public ?string $website = null,
        #[ORM\Column(nullable: true)]
        public ?string $bio = null,
        // Backs the computed read-only `trackCount` attribute (a column here so the
        // entity carries the value the resource reads; core's in-memory domain holds
        // it the same way).
        #[ORM\Column(type: 'integer')]
        public int $trackCount = 0,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->albums = new ArrayCollection();
    }
}
