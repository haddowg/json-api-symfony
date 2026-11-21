<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind `baskets.prices`, whose `discount` pivot field is a
 * `decimal` column typed `string`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'decimal_basket_line')]
#[ORM\HasLifecycleCallbacks]
class BasketLineEntity
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
        #[ORM\ManyToOne(targetEntity: BasketEntity::class, inversedBy: 'lines')]
        public ?BasketEntity $basket = null,
        #[ORM\ManyToOne(targetEntity: PriceEntity::class)]
        public ?PriceEntity $price = null,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        public ?string $discount = null,
    ) {}

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function recordFlush(): void
    {
        self::$flushedDiscounts[] = $this->discount;
    }
}
