<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;

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
     * @param IdEncoderInterface|null   $encoder the parents' type's id encoder, which turns a stored key in another form into the wire id
     */
    public function __construct(
        public string $type,
        public array $parents,
        public array $keys,
        public ?IdEncoderInterface $encoder = null,
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
            $this->encoder,
        );
    }

    /**
     * The wire id of the parent whose key is `$key`, compared as strings, or `null` when
     * no parent has it. A key in another form than the parents' decoded keys, such as the
     * `Uuid` a model holds where the encoder decodes to bytes, matches the parent whose
     * wire id the encoder gives it.
     */
    public function wireIdOf(mixed $key): ?string
    {
        if (!\is_scalar($key) && !$key instanceof \Stringable) {
            return null;
        }

        $wanted = StoredIdForms::comparable(\is_bool($key) || \is_float($key) ? (string) $key : $key);
        foreach ($this->keys as $wireId => $parentKey) {
            if ((\is_int($parentKey) || \is_string($parentKey) || $parentKey instanceof \Stringable) && StoredIdForms::comparable($parentKey) === $wanted) {
                return (string) $wireId;
            }
        }

        if ($this->encoder === null) {
            return null;
        }

        try {
            $wire = $this->encoder->encode($key);
        } catch (\InvalidArgumentException|\UnexpectedValueException|\Error) {
            return null;
        }

        return \array_key_exists($wire, $this->keys) ? $wire : null;
    }
}
