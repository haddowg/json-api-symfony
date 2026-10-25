<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Encodes an integer key as `<prefix><key>`, and decodes only ids carrying its own
 * prefix, so each server's id form is distinct.
 */
final readonly class PrefixIdEncoder implements IdEncoderInterface
{
    public function __construct(private string $prefix) {}

    public function encode(mixed $storageKey): string
    {
        return $this->prefix . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): ?int
    {
        if (!\str_starts_with($wireId, $this->prefix)) {
            return null;
        }

        $key = \substr($wireId, \strlen($this->prefix));

        return \preg_match('/^[0-9]+$/', $key) === 1 ? (int) $key : null;
    }
}
