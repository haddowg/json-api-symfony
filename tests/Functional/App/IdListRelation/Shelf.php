<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdListRelation;

/**
 * A shelf that stores its related books by id, which the in-memory store doesn't
 * support: it needs the related objects themselves.
 */
final class Shelf
{
    /**
     * @param list<string|Book> $bookIds
     */
    public function __construct(
        public string $id = '',
        public array $bookIds = [],
        public string|Book|null $featuredId = null,
    ) {}
}
