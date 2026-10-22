<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * An in-memory gig for the output-timezone conformance pair. Every date value carries
 * the Europe/Paris zone the venue keeps its local times in.
 */
final class Gig
{
    public function __construct(
        public ?int $id = null,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $announcedAt = null,
        public ?\DateTimeImmutable $playedOn = null,
        public ?\DateTimeImmutable $doorsAt = null,
        public ?Venue $venue = null,
    ) {}
}
