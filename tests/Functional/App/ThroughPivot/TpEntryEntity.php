<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use Doctrine\ORM\Mapping as ORM;

/**
 * The pivot row linking a track to a playlist, holding who added it, who audited it and a
 * note no type serves unless a refused declaration registers one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tp_entry')]
class TpEntryEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\ManyToOne(targetEntity: TpPlaylistEntity::class)]
        #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false)]
        public TpPlaylistEntity $playlist,
        #[ORM\ManyToOne(targetEntity: TpTrackEntity::class, inversedBy: 'entries')]
        #[ORM\JoinColumn(name: 'track_id', referencedColumnName: 'id', nullable: false)]
        public TpTrackEntity $track,
        #[ORM\Column]
        public int $position,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $addedAt,
        #[ORM\ManyToOne(targetEntity: TpUserEntity::class)]
        #[ORM\JoinColumn(name: 'added_by_id', referencedColumnName: 'id', nullable: true)]
        public ?TpUserEntity $addedBy = null,
        #[ORM\ManyToOne(targetEntity: TpAuditorEntity::class)]
        #[ORM\JoinColumn(name: 'auditor_id', referencedColumnName: 'id', nullable: true)]
        public ?TpAuditorEntity $auditor = null,
        #[ORM\ManyToOne(targetEntity: TpNoteEntity::class)]
        #[ORM\JoinColumn(name: 'note_id', referencedColumnName: 'id', nullable: true)]
        public ?TpNoteEntity $note = null,
    ) {}
}
