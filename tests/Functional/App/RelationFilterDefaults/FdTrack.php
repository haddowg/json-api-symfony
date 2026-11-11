<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fd_track')]
class FdTrack
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column]
        public bool $explicit = false,
        #[ORM\Column]
        public bool $archived = false,
        #[ORM\ManyToOne(targetEntity: FdAlbum::class, inversedBy: 'tracks')]
        public ?FdAlbum $album = null,
    ) {}
}
