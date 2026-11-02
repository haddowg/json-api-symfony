<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use Doctrine\ORM\Mapping as ORM;

/**
 * A shift whose `startsAt` is a naive `datetime_immutable` column, stored as local
 * time in PHP's default timezone, and whose `day` is a `date_immutable` column.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_shift')]
class ShiftEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $startsAt = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $day = null,
    ) {}
}
