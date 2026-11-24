<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mk_pin')]
class MkPinEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\ManyToOne(targetEntity: MkNoteEntity::class, inversedBy: 'pins')]
        #[ORM\JoinColumn(name: 'note_id', referencedColumnName: 'id', nullable: false)]
        public MkNoteEntity $note,
        #[ORM\Column]
        public string $pinType,
        #[ORM\Column]
        public string $pinId,
    ) {
        $note->pins->add($this);
    }
}
