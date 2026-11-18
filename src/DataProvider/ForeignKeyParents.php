<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

/**
 * The page of parents a relation read through a foreign key answers, each keyed by its
 * wire id: the parent object and the key its related rows' foreign key holds, which is
 * the wire id decoded through the parent type's id encoder
 * ({@see \haddowg\JsonApi\Resource\Field\ForeignKey::parentKey()}). The parents may be
 * served by any provider, so a provider reads their keys from here rather than from its
 * own storage.
 */
final readonly class ForeignKeyParents
{
    /**
     * @param string                    $type    the parents' resource type
     * @param array<int|string, object> $parents each parent, keyed by its wire id (an integer key for a numeric one)
     * @param array<int|string, mixed>  $keys    each parent's stored key, keyed by its wire id
     */
    public function __construct(
        public string $type,
        public array $parents,
        public array $keys,
    ) {}

    /**
     * Whether there are no parents to read for.
     */
    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /**
     * The same parents narrowed to the one with `$wireId`.
     */
    public function only(string $wireId): self
    {
        return new self(
            $this->type,
            \array_intersect_key($this->parents, [$wireId => true]),
            \array_intersect_key($this->keys, [$wireId => true]),
        );
    }

    /**
     * The wire id of the parent whose key is `$key`, compared as strings, or `null` when
     * no parent has it.
     */
    public function wireIdOf(mixed $key): ?string
    {
        if (!\is_scalar($key) && !$key instanceof \Stringable) {
            return null;
        }

        $wanted = (string) $key;
        foreach ($this->keys as $wireId => $parentKey) {
            if ((\is_scalar($parentKey) || $parentKey instanceof \Stringable) && (string) $parentKey === $wanted) {
                return (string) $wireId;
            }
        }

        return null;
    }
}
