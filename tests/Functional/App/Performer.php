<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * An in-memory performer a gig books, for the output-timezone conformance pair.
 */
final class Performer
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
