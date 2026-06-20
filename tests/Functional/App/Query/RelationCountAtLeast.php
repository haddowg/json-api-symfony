<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Query;

use haddowg\JsonApi\Resource\Filter\FilterInterface;

/**
 * A demonstrator **custom filter** value object: `filter[<key>]=N` keeps a resource
 * whose `$relation` to-many has at least `N` related rows. It is neither a `Where`
 * nor any built-in — it exists to prove the extensible-handler seam: the built-in
 * handlers don't recognise it, so it runs only because a registered arm
 * ({@see InMemoryRelationCountFilterArm} / {@see DoctrineRelationCountFilterArm})
 * teaches each provider to execute it. Portable: it ships an arm for both providers.
 */
final class RelationCountAtLeast implements FilterInterface
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

    public function constraints(): array
    {
        return [];
    }
}
