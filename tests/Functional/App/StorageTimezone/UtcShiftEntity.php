<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use Doctrine\ORM\Mapping as ORM;

/**
 * A shift kept in UTC through the application's own column types: `startsAt` is a
 * `datetime_immutable_utc` column and `endsAt` a mutable `datetime_utc` one.
 * `loggedAt` and `onDay` are plain mutable `datetime` and `date` columns.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_utc_shift')]
class UtcShiftEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
        public ?\DateTimeImmutable $startsAt = null,
        #[ORM\Column(type: UtcDateTimeType::NAME, nullable: true)]
        public ?\DateTime $endsAt = null,
        #[ORM\Column(type: 'datetime', nullable: true)]
        public ?\DateTime $loggedAt = null,
        #[ORM\Column(type: 'date', nullable: true)]
        public ?\DateTime $onDay = null,
    ) {}
}
