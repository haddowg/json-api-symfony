<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shift whose `startsAt` is a naive `datetime_immutable` column, stored as local
 * time in PHP's default timezone, and whose `day` is a `date_immutable` column.
 * `startsAtTz` is a zoned column for the parameter-type checks, and `stampedAt` is set
 * by the application rather than the client.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_shift')]
class ShiftEntity
{
    /**
     * Null on an instance the persister creates without its constructor.
     *
     * @var Collection<int, ShiftWorkerEntity>|null
     */
    #[ORM\OneToMany(targetEntity: ShiftWorkerEntity::class, mappedBy: 'shift')]
    public ?Collection $shiftWorkers = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $startsAt = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $day = null,
        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        public ?\DateTimeImmutable $startsAtTz = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $stampedAt = null,
    ) {
        $this->shiftWorkers = new ArrayCollection();
    }
}
