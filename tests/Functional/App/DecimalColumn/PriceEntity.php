<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine reads a `decimal` column as a string, so `price`, `rate` and `amount` are
 * typed `string`, `amount` behind a setter. `legacyPrice` is a decimal column typed
 * `float` and `ratio` a `float` column, both written as floats.
 */
#[ORM\Entity]
#[ORM\Table(name: 'decimal_price')]
#[ORM\HasLifecycleCallbacks]
class PriceEntity
{
    /**
     * Each `price` a flush inserted or updated, in order.
     *
     * @var list<string>
     */
    public static array $flushedPrices = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
        public string $price = '0.00',
        #[ORM\Column(type: 'decimal', precision: 20, scale: 17, nullable: true)]
        public ?string $rate = null,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        private ?string $amount = null,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        public ?float $legacyPrice = null,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $ratio = null,
    ) {}

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): void
    {
        $this->amount = $amount;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function recordFlush(): void
    {
        self::$flushedPrices[] = $this->price;
    }
}
