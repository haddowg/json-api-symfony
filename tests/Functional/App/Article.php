<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain domain model fed to the in-memory provider — no base class, no ORM —
 * mirroring the core getting-started example.
 */
final class Article
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public string $category = '',
    ) {}
}
