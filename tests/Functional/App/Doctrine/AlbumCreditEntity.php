<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The second association entity reaching {@see TrackEntity} from an
 * {@see AlbumEntity} (see {@see AlbumEntity}), which makes auto-detection of an
 * album→tracks pivot ambiguous. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'album_credit')]
class AlbumCreditEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: AlbumEntity::class, inversedBy: 'albumCredits')]
        public ?AlbumEntity $album = null,
        #[ORM\ManyToOne(targetEntity: TrackEntity::class)]
        public ?TrackEntity $track = null,
        #[ORM\Column]
        public string $role = '',
    ) {}
}
