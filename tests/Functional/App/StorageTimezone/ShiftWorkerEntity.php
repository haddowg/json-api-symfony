<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind a shift's workers, with a writable naive `checkedInAt`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tz_shift_worker')]
class ShiftWorkerEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: ShiftEntity::class, inversedBy: 'shiftWorkers')]
        public ?ShiftEntity $shift = null,
        #[ORM\ManyToOne(targetEntity: WorkerEntity::class)]
        public ?WorkerEntity $worker = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $checkedInAt = null,
    ) {}
}
