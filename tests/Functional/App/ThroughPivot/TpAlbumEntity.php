<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tp_album')]
class TpAlbumEntity
{
    /**
     * @var Collection<int, TpTrackEntity>
     */
    #[ORM\OneToMany(targetEntity: TpTrackEntity::class, mappedBy: 'album')]
    public Collection $tracks;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $title,
    ) {
        $this->tracks = new ArrayCollection();
    }
}
