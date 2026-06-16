<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * A trivial reversible id codec for the Doctrine encoded-id witness (bundle ADR
 * 0038): the storage key is the entity's integer primary key, the wire id its
 * `cog-`-prefixed hex encoding, so storage != wire. {@see decode()} returns the
 * integer storage key (the type {@see CogEntity}'s int-typed `id` column requires —
 * Doctrine's strict typed-property assignment on create does not coerce a string),
 * or `null` for a token it did not produce (the Doctrine provider treats that as a
 * `404`; the persister as a bad linkage target).
 */
final class HexIdEncoder implements IdEncoderInterface
{
    public const string PREFIX = 'cog-';

    public function encode(mixed $storageKey): string
    {
        $key = \is_scalar($storageKey) ? (string) $storageKey : '';

        return self::PREFIX . \bin2hex($key);
    }

    public function decode(string $wireId): ?int
    {
        if (!\str_starts_with($wireId, self::PREFIX)) {
            return null;
        }

        $hex = \substr($wireId, \strlen(self::PREFIX));
        if ($hex === '' || (\strlen($hex) % 2) !== 0) {
            return null;
        }

        $bytes = \hex2bin($hex);
        if ($bytes === false || \preg_match('/^[0-9]+$/', $bytes) !== 1) {
            return null;
        }

        return (int) $bytes;
    }
}
