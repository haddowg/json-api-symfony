<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\ORM\Mapping as ORM;

/**
 * The `ownedNotes` entity: an owner and the owning `folder` reference of the
 * folder/notes one-to-many. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'owned_note')]
class OwnedNoteEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $owner = '',
        #[ORM\ManyToOne(targetEntity: OwnedFolderEntity::class, inversedBy: 'notes')]
        #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: true)]
        public ?OwnedFolderEntity $folder = null,
    ) {}
}
