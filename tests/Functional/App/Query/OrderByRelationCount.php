<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Query;

use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * A demonstrator **custom sort** value object: `sort=<key>` orders a resource by the
 * size of its `$relation` to-many (the Laravel "sort by relationship count" parity
 * case). Not a `SortByField`, so it runs only because a registered arm
 * ({@see InMemoryRelationCountSortArm} / {@see DoctrineRelationCountSortArm}) teaches
 * each provider to order by the count — the sort half of the extensible-handler seam.
 */
final class OrderByRelationCount implements SortInterface
{
    private function __construct(
        private readonly string $key,
        public readonly string $relation,
    ) {}

    public static function make(string $key, string $relation): self
    {
        return new self($key, $relation);
    }

    public function key(): string
    {
        return $this->key;
    }
}
