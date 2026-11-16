<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An inbox item about another resource, its subject kept as a stored type and id, with
 * read and archived state kept as nullable timestamps.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inb_item')]
class InboxItemEntity
{
    /**
     * @var Collection<int, InboxLinkEntity>
     */
    #[ORM\OneToMany(targetEntity: InboxLinkEntity::class, mappedBy: 'item')]
    public Collection $links;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $title,
        #[ORM\ManyToOne(targetEntity: InboxFolderEntity::class, inversedBy: 'items')]
        #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: true)]
        public ?InboxFolderEntity $folder = null,
        #[ORM\Column(nullable: true)]
        public ?string $subjectType = null,
        #[ORM\Column(nullable: true)]
        public ?string $subjectId = null,
        #[ORM\Column(nullable: true)]
        public ?\DateTimeImmutable $readTs = null,
        #[ORM\Column(nullable: true)]
        public ?\DateTimeImmutable $archivedTs = null,
    ) {
        $this->links = new ArrayCollection();
    }
}
