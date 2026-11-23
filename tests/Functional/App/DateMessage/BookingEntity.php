<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DateMessage;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dm_booking')]
class BookingEntity
{
    /**
     * @param ?array<string, mixed> $window
     * @param ?array<string, mixed> $marks
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $startsAt = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $endsAt = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $day = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $dueOn = null,
        #[ORM\Column(type: 'time_immutable', nullable: true)]
        public ?\DateTimeImmutable $opensAt = null,
        #[ORM\Column(type: 'time_immutable', nullable: true)]
        public ?\DateTimeImmutable $closesAt = null,
        #[ORM\Column(type: 'time_immutable', nullable: true)]
        public ?\DateTimeImmutable $breakAt = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $window = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public mixed $until = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $marks = null,
    ) {}
}
