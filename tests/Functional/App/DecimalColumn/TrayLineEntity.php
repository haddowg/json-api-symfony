<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use Doctrine\ORM\Mapping as ORM;

/**
 * A link row of `trays.prices`, naming its member by type and id, whose `discount` pivot
 * field is a `decimal` column typed `string`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'decimal_tray_line')]
#[ORM\HasLifecycleCallbacks]
class TrayLineEntity
{
    /**
     * Each `discount` a flush inserted or updated, in order.
     *
     * @var list<?string>
     */
    public static array $flushedDiscounts = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: TrayEntity::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false)]
        public ?TrayEntity $tray = null,
        #[ORM\Column]
        public string $itemType = '',
        #[ORM\Column]
        public int $itemId = 0,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        public ?string $discount = null,
    ) {}

    public function key(): string
    {
        return $this->itemType . ':' . $this->itemId;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function recordFlush(): void
    {
        self::$flushedDiscounts[] = $this->discount;
    }
}
