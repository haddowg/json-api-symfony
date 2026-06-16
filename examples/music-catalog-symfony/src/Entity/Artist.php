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
 * The id is a **database-assigned auto-increment integer** — the store-provided id
 * default (the example's norm): a create sets nothing on the id and the DB assigns
 * it on flush, read back on the `201`. The JSON:API `id` is the integer as a string.
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'artist')]
class Artist
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * The inverse side of the album→artist association: an artist's albums,
     * mapped by {@see Album}'s owning `artist` reference.
     *
     * @var Collection<int, Album>
     */
    #[ORM\OneToMany(targetEntity: Album::class, mappedBy: 'artist')]
    public Collection $albums;

    public function __construct(
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
