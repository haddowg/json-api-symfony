<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use Doctrine\ORM\Mapping as ORM;

/**
 * A message in an {@see Inbox}, served as `inbox-items`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'singleton_inbox_item')]
class InboxItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $subject = '';

    #[ORM\ManyToOne(targetEntity: Inbox::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true)]
    public ?Inbox $inbox = null;

    public function __construct(?int $id = null, string $subject = '', ?Inbox $inbox = null)
    {
        $this->id = $id;
        $this->subject = $subject;
        $this->inbox = $inbox;
        $inbox?->items->add($this);
    }
}
