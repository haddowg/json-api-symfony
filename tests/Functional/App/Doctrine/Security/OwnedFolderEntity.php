<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The `ownedFolders` entity: an owner and the inverse side of a one-to-many to
 * {@see OwnedNoteEntity}, whose `folder` reference carries the foreign key. Not
 * `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'owned_folder')]
class OwnedFolderEntity
{
    /**
     * @var Collection<int, OwnedNoteEntity>
     */
    #[ORM\OneToMany(targetEntity: OwnedNoteEntity::class, mappedBy: 'folder')]
    public Collection $notes;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $owner = '',
    ) {
        $this->notes = new ArrayCollection();
    }
}
