<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * An in-memory venue, included from a {@see Gig} in the output-timezone conformance pair.
 */
final class Venue
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public ?\DateTimeImmutable $openedAt = null,
    ) {}
}
