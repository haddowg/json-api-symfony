<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\ORM\Mapping as ORM;

/**
 * A label that always belongs to a folder: its foreign key is NOT NULL, so it can't
 * be removed from one without a new parent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'folder_label')]
class FolderLabelEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: OwnedFolderEntity::class, inversedBy: 'labels')]
        #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: false)]
        public ?OwnedFolderEntity $folder = null,
    ) {}
}
