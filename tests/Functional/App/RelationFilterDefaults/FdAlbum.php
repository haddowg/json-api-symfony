<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fd_album')]
class FdAlbum
{
    /**
     * @var Collection<int, FdTrack>
     */
    #[ORM\OneToMany(targetEntity: FdTrack::class, mappedBy: 'album')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $tracks;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
    ) {
        $this->tracks = new ArrayCollection();
    }
}
