<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

/**
 * A value object a custom Doctrine type stores as an integer, rendered as `c-<n>`.
 */
final readonly class Code implements \Stringable
{
    public function __construct(public int $value) {}

    public static function parse(mixed $value): ?self
    {
        return match (true) {
            $value instanceof self => $value,
            \is_int($value) => new self($value),
            \is_string($value) && \preg_match('/^(?:c-)?(\d+)$/', $value, $match) === 1 => new self((int) $match[1]),
            default => null,
        };
    }

    public static function fromString(string $value): self
    {
        return self::parse($value) ?? throw new \InvalidArgumentException('Not a code.');
    }

    public function __toString(): string
    {
        return 'c-' . $this->value;
    }
}
