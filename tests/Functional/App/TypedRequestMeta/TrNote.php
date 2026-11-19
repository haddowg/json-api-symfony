<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note holding items, whose writes declare their request meta and whose resource
 * objects declare their meta.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tr_note')]
class TrNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title = '';

    #[ORM\Column(type: 'integer')]
    public int $revision = 1;

    /** Set by the `source` resource meta member's fillUsing() hook. */
    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $source = null;

    /** Set by the create hook from the request meta's idempotency key. */
    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $createdWith = null;

    /** @var Collection<int, TrItem> */
    #[ORM\ManyToMany(targetEntity: TrItem::class)]
    #[ORM\JoinTable(name: 'tr_note_item')]
    public Collection $items;

    #[ORM\ManyToOne(targetEntity: TrItem::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?TrItem $lead = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }
}
