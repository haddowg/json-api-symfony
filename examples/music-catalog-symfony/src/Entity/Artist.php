<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\PrePersistEventArgs;
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
 *
 * `slug` is optional on create. A new artist sent without one gets it derived from
 * `name` by the `#[ORM\PrePersist]` callback below, suffixed (`-2`, `-3`, ...) when
 * another artist already holds it, so `filter[slug]` keeps matching one artist. The
 * resource declares `minLength(1)`, so a write that sends an empty slug is a `422`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'artist')]
#[ORM\HasLifecycleCallbacks]
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

    #[ORM\Column]
    public string $name = '';

    #[ORM\Column]
    public string $slug = '';

    #[ORM\Column(nullable: true)]
    public ?string $website = null;

    #[ORM\Column(nullable: true)]
    public ?string $bio = null;

    // Backs the computed read-only `trackCount` attribute (a column here so the
    // entity carries the value the resource reads; core's in-memory domain holds
    // it the same way).
    #[ORM\Column(type: 'integer')]
    public int $trackCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $createdAt = null;

    public function __construct(
        string $name = '',
        string $slug = '',
        ?string $website = null,
        ?string $bio = null,
        int $trackCount = 0,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->name = $name;
        $this->slug = $slug;
        $this->website = $website;
        $this->bio = $bio;
        $this->trackCount = $trackCount;
        $this->createdAt = $createdAt;
        $this->albums = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function deriveSlug(PrePersistEventArgs $event): void
    {
        if ($this->slug !== '') {
            return;
        }

        $base = \trim(\preg_replace('/[^a-z0-9]+/', '-', \strtolower($this->name)) ?? '', '-');
        if ($base === '') {
            $base = 'artist';
        }

        $manager = $event->getObjectManager();
        $taken = $manager->createQueryBuilder()
            ->select('a.slug')
            ->from(self::class, 'a')
            ->where('a.slug = :base OR a.slug LIKE :suffixed')
            ->setParameter('base', $base)
            ->setParameter('suffixed', $base . '-%')
            ->getQuery()
            ->getSingleColumnResult();
        // Artists persisted earlier in the same flush are not in the table yet.
        foreach ($manager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof self && $pending !== $this) {
                $taken[] = $pending->slug;
            }
        }

        $slug = $base;
        for ($n = 2; \in_array($slug, $taken, true); ++$n) {
            $slug = $base . '-' . $n;
        }
        $this->slug = $slug;
    }
}
