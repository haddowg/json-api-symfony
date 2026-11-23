<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DateMessage;

final class Booking
{
    /**
     * @param ?array<string, mixed> $window
     * @param ?array<string, mixed> $marks
     */
    public function __construct(
        public ?int $id = null,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $endsAt = null,
        public ?\DateTimeImmutable $day = null,
        public ?\DateTimeImmutable $dueOn = null,
        public ?\DateTimeImmutable $opensAt = null,
        public ?\DateTimeImmutable $closesAt = null,
        public ?\DateTimeImmutable $breakAt = null,
        public ?array $window = null,
        public mixed $until = null,
        public ?array $marks = null,
    ) {}
}
