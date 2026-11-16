<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * One resource an inbox item links to, as a stored type and id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inb_link')]
class InboxLinkEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\ManyToOne(targetEntity: InboxItemEntity::class, inversedBy: 'links')]
        #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false)]
        public InboxItemEntity $item,
        #[ORM\Column]
        public string $linkedType,
        #[ORM\Column]
        public string $linkedId,
    ) {}
}
