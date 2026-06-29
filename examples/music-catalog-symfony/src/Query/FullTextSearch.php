<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Query;

use haddowg\JsonApi\Resource\Filter\DescribedFilter;

/**
 * A demonstrator **custom full-text filter**: `filter[<key>]=term` keeps a resource
 * whose any of the declared text `$fields` contains `term` (case-insensitive
 * substring). It is neither a `Where` nor any built-in — it exists to show the
 * extensible-filter seam: the built-in handlers don't recognise it, so it runs only
 * because a registered arm ({@see DoctrineFullTextSearchArm}) teaches the provider to
 * execute it. The example serves over Doctrine, so it ships the Doctrine arm only; a
 * portable filter would additionally ship an `ArrayFilterArmInterface` witness.
 *
 * Implements {@see DescribedFilter} so the OpenAPI generator surfaces a meaningful
 * description on the `filter[<key>]` parameter rather than the generic default.
 */
final class FullTextSearch implements DescribedFilter
{
    /**
     * @param list<string> $fields the entity field names searched (OR-ed together)
     */
    private function __construct(
        private readonly string $key,
        public readonly array $fields,
    ) {}

    /**
     * @param list<string> $fields the entity field names searched (OR-ed together)
     */
    public static function make(string $key, array $fields): self
    {
        return new self($key, $fields);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function constraints(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return \sprintf(
            'Case-insensitive substring search across %s.',
            \implode(', ', $this->fields),
        );
    }
}
