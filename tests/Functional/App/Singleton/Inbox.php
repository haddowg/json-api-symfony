<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's inbox, served as the `inboxes` singleton at `/inbox`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'singleton_inbox')]
class Inbox
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $owner = '';

    #[ORM\Column(type: 'string')]
    public string $title = '';

    #[ORM\Column(type: 'integer')]
    public int $unread = 0;

    #[ORM\ManyToOne(targetEntity: InboxItem::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?InboxItem $pinned = null;

    /**
     * @var Collection<int, InboxItem>
     */
    #[ORM\OneToMany(targetEntity: InboxItem::class, mappedBy: 'inbox')]
    public Collection $items;

    public function __construct(?int $id = null, string $owner = '', string $title = '', int $unread = 0)
    {
        $this->id = $id;
        $this->owner = $owner;
        $this->title = $title;
        $this->unread = $unread;
        $this->items = new ArrayCollection();
    }
}
