<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inb_folder')]
class InboxFolderEntity
{
    /**
     * @var Collection<int, InboxItemEntity>
     */
    #[ORM\OneToMany(targetEntity: InboxItemEntity::class, mappedBy: 'folder')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $items;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
    ) {
        $this->items = new ArrayCollection();
    }
}
