<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tp_track')]
class TpTrackEntity
{
    /**
     * @var Collection<int, TpEntryEntity>
     */
    #[ORM\OneToMany(targetEntity: TpEntryEntity::class, mappedBy: 'track')]
    public Collection $entries;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $title,
        #[ORM\ManyToOne(targetEntity: TpAlbumEntity::class, inversedBy: 'tracks')]
        #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: true)]
        public ?TpAlbumEntity $album = null,
    ) {
        $this->entries = new ArrayCollection();
    }
}
