<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StorageTimezone;

final class Shift
{
    public function __construct(
        public ?int $id = null,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $day = null,
    ) {}
}
