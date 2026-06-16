<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The ambiguity witness for pivot auto-detection: an album owns TWO to-many
 * associations to DIFFERENT association entities ({@see AlbumTrackEntity},
 * {@see AlbumCreditEntity}) that BOTH reach the far {@see TrackEntity}. So a
 * `belongsToMany` pivot to `tracks` declared here cannot be auto-detected — the
 * resolver throws a {@see \LogicException} unless the author names the intended
 * association entity with `->through(AlbumTrackEntity::class)`. Exercised directly
 * in {@see \haddowg\JsonApiBundle\Tests\DataProvider\Doctrine\PivotAssociationResolverTest}.
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'album')]
class AlbumEntity
{
    /**
     * @var Collection<int, AlbumTrackEntity>
     */
    #[ORM\OneToMany(targetEntity: AlbumTrackEntity::class, mappedBy: 'album')]
    public Collection $albumTracks;

    /**
     * @var Collection<int, AlbumCreditEntity>
     */
    #[ORM\OneToMany(targetEntity: AlbumCreditEntity::class, mappedBy: 'album')]
    public Collection $albumCredits;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->albumTracks = new ArrayCollection();
        $this->albumCredits = new ArrayCollection();
    }
}
