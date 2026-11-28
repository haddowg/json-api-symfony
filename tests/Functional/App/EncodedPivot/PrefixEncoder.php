<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EncodedPivot;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Renders a stored id behind a prefix, so a wire id is never the id a record keeps.
 */
final readonly class PrefixEncoder implements IdEncoderInterface
{
    public function __construct(private string $prefix) {}

    public function encode(mixed $storageKey): string
    {
        return $this->prefix . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): ?string
    {
        return \str_starts_with($wireId, $this->prefix) ? \substr($wireId, \strlen($this->prefix)) : null;
    }
}
